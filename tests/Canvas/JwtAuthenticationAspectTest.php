<?php
	
	namespace Quellabs\Canvas\Tests\Security;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\JwtAuthenticationException;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\JwtAuthenticationAspect;
	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Quellabs\Contracts\Configuration\ConfigurationInterface;
	use Symfony\Component\HttpFoundation\Request;
	
	/**
	 * Unit tests for JwtAuthenticationAspect.
	 *
	 * Each test constructs the aspect directly with a mock config provider,
	 * builds a minimal MethodContext stub carrying a crafted Request, and
	 * asserts on request attributes (attribute mode) or thrown exceptions
	 * (exception mode). No framework boot or HTTP kernel required.
	 */
	class JwtAuthenticationAspectTest extends TestCase {
		
		// =========================================================================
		// Helpers
		// =========================================================================
		
		private string $secret = 'test-secret-key';
		
		/**
		 * Build a ConfigProviderInterface mock whose loadConfigFile() returns a
		 * ConfigurationInterface mock that serves the given key/value pairs.
		 * @param array<string, mixed> $values
		 * @return ConfigProviderInterface
		 */
		private function makeConfigProvider(array $values = []): ConfigProviderInterface {
			$config = $this->createMock(ConfigurationInterface::class);
			
			// Route get($key, $default) to the provided values array
			$config->method('get')->willReturnCallback(
				function (string $key, mixed $default = null) use ($values): mixed {
					return $values[$key] ?? $default;
				}
			);
			
			$provider = $this->createMock(ConfigProviderInterface::class);
			$provider->method('loadConfigFile')->willReturn($config);
			
			return $provider;
		}
		
		/**
		 * Build a MethodContextInterface stub carrying the given Request.
		 * @param Request $request
		 * @return MethodContextInterface
		 */
		private function makeContext(Request $request): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			return $context;
		}
		
		/**
		 * Build a valid HS256 JWT signed with $this->secret.
		 * @param array<string, mixed> $payload
		 * @param array<string, mixed> $headerOverrides
		 * @return string
		 */
		private function makeToken(array $payload = [], array $headerOverrides = []): string {
			$header = array_merge(['alg' => 'HS256', 'typ' => 'JWT'], $headerOverrides);
			
			$encode = fn(array $data): string => rtrim(
				strtr(base64_encode(json_encode($data)), '+/', '-_'),
				'='
			);
			
			$headerB64  = $encode($header);
			$payloadB64 = $encode($payload);
			$signature  = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret, true);
			$sigB64     = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
			
			return $headerB64 . '.' . $payloadB64 . '.' . $sigB64;
		}
		
		/**
		 * Build a default valid payload with a future expiry.
		 * @param array<string, mixed> $overrides
		 * @return array<string, mixed>
		 */
		private function makePayload(array $overrides = []): array {
			return array_merge([
				'sub' => 'user-123',
				'exp' => time() + 3600,
				'iat' => time(),
			], $overrides);
		}
		
		/**
		 * Construct JwtAuthenticationAspect with the given config values and optional
		 * annotation-level overrides.
		 */
		private function makeAspect(
			array $configValues = [],
			string $secret = '',
			string $algorithm = '',
			?bool $throwOnFailure = null,
			?int $clockSkew = null,
			string $issuer = '',
			string $audience = ''
		): JwtAuthenticationAspect {
			// Provide the secret via config when no annotation override is given
			if (empty($secret) && !isset($configValues['secret'])) {
				$configValues['secret'] = $this->secret;
			}
			
			return new JwtAuthenticationAspect(
				$this->makeConfigProvider($configValues),
				$secret,
				$algorithm,
				$throwOnFailure,
				$clockSkew,
				$issuer,
				$audience
			);
		}
		
		// =========================================================================
		// Constructor — configuration resolution
		// =========================================================================
		
		public function testConstructorThrowsWhenSecretIsEmpty(): void {
			$this->expectException(\RuntimeException::class);
			new JwtAuthenticationAspect($this->makeConfigProvider([]));
		}
		
		public function testConstructorThrowsWhenAlgorithmIsUnsupported(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->makeAspect(['algorithm' => 'RS256']);
		}
		
		public function testConstructorThrowsWhenAnnotationAlgorithmIsUnsupported(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->makeAspect([], algorithm: 'none');
		}
		
		public function testConstructorThrowsWhenClockSkewIsNegative(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->makeAspect(['clock_skew' => -1]);
		}
		
		public function testConstructorThrowsWhenAnnotationClockSkewIsNegative(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->makeAspect([], clockSkew: -1);
		}
		
		public function testAnnotationSecretOverridesConfig(): void {
			// If the annotation secret is wrong the signature will fail — that proves
			// the annotation value was used rather than the config value
			$aspect  = $this->makeAspect(['secret' => $this->secret], secret: 'wrong-secret');
			$token   = $this->makeToken($this->makePayload());
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			$context = $this->makeContext($request);
			
			$aspect->before($context);
			
			// Wrong secret means signature fails → jwt_error is set
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testAnnotationThrowOnFailureOverridesConfig(): void {
			// Config says false, annotation says true — exception should be thrown
			$aspect  = $this->makeAspect(['throw_on_failure' => false], throwOnFailure: true);
			$request = Request::create('/');
			// No Authorization header → validation fails
			$context = $this->makeContext($request);
			
			$this->expectException(JwtAuthenticationException::class);
			$aspect->before($context);
		}
		
		// =========================================================================
		// Attribute mode — success path
		// =========================================================================
		
		public function testValidTokenSetsJwtPayloadAttribute(): void {
			$payload = $this->makePayload();
			$token   = $this->makeToken($payload);
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertIsArray($request->attributes->get('jwt_payload'));
		}
		
		public function testValidTokenSetsJwtUserIdFromSubClaim(): void {
			$token   = $this->makeToken($this->makePayload(['sub' => 'user-42']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertSame('user-42', $request->attributes->get('jwt_user_id'));
		}
		
		public function testValidTokenSetsJwtUserIdToNullWhenSubAbsent(): void {
			$token   = $this->makeToken($this->makePayload(['sub' => null]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_user_id'));
		}
		
		public function testValidTokenClearsStaleJwtError(): void {
			$request = Request::create('/');
			$request->attributes->set('jwt_error', 'stale error');
			$token = $this->makeToken($this->makePayload());
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testBeforeReturnsNullOnSuccess(): void {
			$token   = $this->makeToken($this->makePayload());
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$result = $this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($result);
		}
		
		// =========================================================================
		// Attribute mode — failure path
		// =========================================================================
		
		public function testMissingAuthorizationHeaderSetsJwtError(): void {
			$request = Request::create('/');
			$this->makeAspect()->before($this->makeContext($request));
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testMissingAuthorizationHeaderClearsJwtPayload(): void {
			$request = Request::create('/');
			$request->attributes->set('jwt_payload', ['sub' => 'old']);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_payload'));
		}
		
		public function testMissingAuthorizationHeaderClearsJwtUserId(): void {
			$request = Request::create('/');
			$request->attributes->set('jwt_user_id', 'old-user');
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_user_id'));
		}
		
		public function testNonBearerSchemeSetsJwtError(): void {
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testBeforeReturnsNullOnFailureInAttributeMode(): void {
			$request = Request::create('/');
			$result  = $this->makeAspect()->before($this->makeContext($request));
			$this->assertNull($result);
		}
		
		// =========================================================================
		// Exception mode
		// =========================================================================
		
		public function testMissingHeaderThrowsInExceptionMode(): void {
			$aspect  = $this->makeAspect(throwOnFailure: true);
			$request = Request::create('/');
			
			$this->expectException(JwtAuthenticationException::class);
			$aspect->before($this->makeContext($request));
		}
		
		public function testInvalidSignatureThrowsInExceptionMode(): void {
			$aspect  = $this->makeAspect(throwOnFailure: true);
			$token   = $this->makeToken($this->makePayload()) . 'tampered';
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->expectException(JwtAuthenticationException::class);
			$aspect->before($this->makeContext($request));
		}
		
		// =========================================================================
		// Token structure
		// =========================================================================
		
		public function testMalformedTokenStructureSetsJwtError(): void {
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer not.a.valid.jwt.structure');
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testTwoSegmentTokenSetsJwtError(): void {
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer only.twosegments');
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testWrongAlgorithmInHeaderSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(), ['alg' => 'RS256']);
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testMissingAlgInHeaderSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(), ['alg' => null]);
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testNoneAlgorithmInHeaderSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(), ['alg' => 'none']);
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Signature
		// =========================================================================
		
		public function testWrongSecretSetsJwtError(): void {
			$aspect  = new JwtAuthenticationAspect(
				$this->makeConfigProvider(['secret' => 'correct-secret']),
			);
			$token   = $this->makeToken($this->makePayload()); // signed with $this->secret
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$aspect->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testTamperedPayloadSetsJwtError(): void {
			$parts      = explode('.', $this->makeToken($this->makePayload()));
			$tampered   = rtrim(strtr(base64_encode(json_encode(['sub' => 'attacker', 'exp' => time() + 9999])), '+/', '-_'), '=');
			$parts[1]   = $tampered;
			$token      = implode('.', $parts);
			$request    = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Expiry (exp)
		// =========================================================================
		
		public function testExpiredTokenSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['exp' => time() - 3600]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			// Zero clock skew so the expired token is not accidentally accepted
			$this->makeAspect(clockSkew: 0)->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testTokenExpiredJustBeyondClockSkewSetsJwtError(): void {
			// Expired 40 seconds ago, clock skew is 30 — should be rejected
			$token   = $this->makeToken($this->makePayload(['exp' => time() - 40]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(clockSkew: 30)->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testTokenExpiredWithinClockSkewIsAccepted(): void {
			// Expired 20 seconds ago, clock skew is 30 — should be accepted
			$token   = $this->makeToken($this->makePayload(['exp' => time() - 20]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(clockSkew: 30)->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testFloatExpIsAccepted(): void {
			// Some issuers emit floating-point NumericDate values
			$token   = $this->makeToken($this->makePayload(['exp' => (float)(time() + 3600)]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testStringExpSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['exp' => 'tomorrow']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Not-before (nbf)
		// =========================================================================
		
		public function testTokenNotYetValidSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['nbf' => time() + 3600]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(clockSkew: 0)->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testTokenNbfWithinClockSkewIsAccepted(): void {
			// nbf is 20 seconds in the future, clock skew is 30 — should be accepted
			$token   = $this->makeToken($this->makePayload(['nbf' => time() + 20]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(clockSkew: 30)->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testTokenNbfBeyondClockSkewSetsJwtError(): void {
			// nbf is 40 seconds in the future, clock skew is 30 — should be rejected
			$token   = $this->makeToken($this->makePayload(['nbf' => time() + 40]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(clockSkew: 30)->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testFloatNbfIsAccepted(): void {
			$token   = $this->makeToken($this->makePayload(['nbf' => (float)(time() - 10)]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testStringNbfSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['nbf' => 'yesterday']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Issued-at (iat)
		// =========================================================================
		
		public function testStringIatSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['iat' => 'now']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testFloatIatIsAccepted(): void {
			$token   = $this->makeToken($this->makePayload(['iat' => (float)time()]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Issuer (iss)
		// =========================================================================
		
		public function testValidIssuerIsAccepted(): void {
			$token   = $this->makeToken($this->makePayload(['iss' => 'auth.example.com']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(issuer: 'auth.example.com')->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testWrongIssuerSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['iss' => 'evil.example.com']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(issuer: 'auth.example.com')->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testMissingIssuerClaimSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload());
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(issuer: 'auth.example.com')->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testIssuerNotValidatedWhenEmpty(): void {
			// No issuer configured — iss claim in token should be ignored
			$token   = $this->makeToken($this->makePayload(['iss' => 'anything']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Audience (aud)
		// =========================================================================
		
		public function testValidStringAudienceIsAccepted(): void {
			$token   = $this->makeToken($this->makePayload(['aud' => 'api.example.com']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(audience: 'api.example.com')->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testValidArrayAudienceIsAccepted(): void {
			$token   = $this->makeToken($this->makePayload(['aud' => ['api.example.com', 'admin.example.com']]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(audience: 'api.example.com')->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		public function testWrongStringAudienceSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['aud' => 'other.example.com']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(audience: 'api.example.com')->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testAudienceNotInArraySetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['aud' => ['other.example.com']]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(audience: 'api.example.com')->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testAudienceArrayWithNonStringEntrySetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['aud' => [123, 'api.example.com']]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect(audience: 'api.example.com')->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testAudienceNotValidatedWhenEmpty(): void {
			// No audience configured — aud claim in token should be ignored
			$token   = $this->makeToken($this->makePayload(['aud' => 'anything']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Sub claim type
		// =========================================================================
		
		public function testIntegerSubSetsJwtError(): void {
			$token   = $this->makeToken($this->makePayload(['sub' => 42]));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
		
		public function testStringSubIsAccepted(): void {
			$token   = $this->makeToken($this->makePayload(['sub' => 'user-1']));
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $token);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNull($request->attributes->get('jwt_error'));
		}
		
		// =========================================================================
		// Payload structure
		// =========================================================================
		
		public function testJsonArrayPayloadSetsJwtError(): void {
			// A JSON array payload should be rejected — JWT payloads must be objects
			$header  = ['alg' => 'HS256', 'typ' => 'JWT'];
			$encode  = fn(mixed $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
			
			$headerB64  = $encode($header);
			$payloadB64 = $encode(['HS256', 'value']); // JSON array, not object
			$sig        = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret, true);
			$sigB64     = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
			
			$request = Request::create('/');
			$request->headers->set('Authorization', 'Bearer ' . $headerB64 . '.' . $payloadB64 . '.' . $sigB64);
			
			$this->makeAspect()->before($this->makeContext($request));
			
			$this->assertNotNull($request->attributes->get('jwt_error'));
		}
	}