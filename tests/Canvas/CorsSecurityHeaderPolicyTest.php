<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Configuration\Configuration;
	use Quellabs\Canvas\Security\Policies\CorsSecurityHeaderPolicy;
	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Tests CorsSecurityHeaderPolicy: deny-by-default behavior, exact origin
	 * matching (including effective port), ordinary vs. preflight requests,
	 * route-owned pre-existing CORS headers, and Vary merging.
	 */
	class CorsSecurityHeaderPolicyTest extends TestCase {

		/**
		 * @param array<string, mixed> $config
		 */
		private function makePolicy(array $config): CorsSecurityHeaderPolicy {
			$provider = $this->createStub(ConfigProviderInterface::class);
			$provider->method('loadConfigFile')->willReturn(new Configuration($config));
			return new CorsSecurityHeaderPolicy($provider);
		}

		private function actualRequest(string $origin): Request {
			return Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => $origin]);
		}

		private function preflightRequest(string $origin, string $method, ?string $headers = null): Request {
			$server = [
				'HTTP_ORIGIN'                          => $origin,
				'HTTP_ACCESS_CONTROL_REQUEST_METHOD'  => $method,
			];

			if ($headers !== null) {
				$server['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = $headers;
			}

			return Request::create('/', 'OPTIONS', [], [], [], $server);
		}

		// --- Absent configuration / deny by default -------------------------------------

		public function testAbsentConfigurationGrantsNoCrossOriginAccess(): void {
			$policy = $this->makePolicy([]);
			$response = new Response();

			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
			$this->assertSame('Origin', $response->headers->get('Vary'));
		}

		public function testRequestWithoutOriginIsLeftCompletelyUnchanged(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
			$this->assertFalse($response->headers->has('Vary'));
		}

		// --- Ordinary request: allowed / disallowed origins -----------------------------

		public function testDisallowedOriginGetsNoApprovalHeadersButStillVaries(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response();

			$policy->apply($this->actualRequest('https://evil.example.com'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
			$this->assertSame('Origin', $response->headers->get('Vary'));
		}

		public function testAllowedOriginGetsApprovalCredentialsAndExposeHeaders(): void {
			$policy = $this->makePolicy([
				'allowed_origins'   => ['https://app.example.com'],
				'exposed_headers'   => ['Location'],
				'allow_credentials' => true,
			]);
			$response = new Response();

			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertSame('https://app.example.com', $response->headers->get('Access-Control-Allow-Origin'));
			$this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
			$this->assertSame('Location', $response->headers->get('Access-Control-Expose-Headers'));
		}

		public function testCredentialsHeaderOmittedWhenNotConfigured(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response();

			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertSame('https://app.example.com', $response->headers->get('Access-Control-Allow-Origin'));
			$this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
		}

		// --- Exact port matching ----------------------------------------------------------

		public function testAnExplicitNonDefaultPortMustMatchExactly(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com:8443']]);
			$response = new Response();

			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
		}

		public function testAnImplicitDefaultPortMatchesTheSameExplicitPort(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response();

			// Browsers omit the default port, but an admin might configure it explicitly.
			$policy->apply($this->actualRequest('https://app.example.com:443'), $response);

			$this->assertSame('https://app.example.com:443', $response->headers->get('Access-Control-Allow-Origin'));
		}

		// --- Rejecting '*' and 'null' -------------------------------------------------------

		public function testWildcardOriginInConfigurationIsRejectedAtConstruction(): void {
			$this->expectException(\RuntimeException::class);

			$this->makePolicy(['allowed_origins' => ['*']]);
		}

		public function testOpaqueNullOriginInConfigurationIsRejectedAtConstruction(): void {
			$this->expectException(\RuntimeException::class);

			$this->makePolicy(['allowed_origins' => ['null']]);
		}

		public function testAnOpaqueNullOriginHeaderAtRequestTimeIsNeverAllowed(): void {
			// Even though it can never appear in allowed_origins, prove a literal
			// 'null' Origin request header is also never matched at runtime.
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response();

			$policy->apply($this->actualRequest('null'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
		}

		// --- Route-owned pre-existing CORS headers -----------------------------------------

		public function testExistingAccessControlAllowOriginIsTreatedAsRouteOwned(): void {
			$policy = $this->makePolicy([
				'allowed_origins'   => ['https://app.example.com'],
				'allow_credentials' => true,
			]);
			$response = new Response();
			$response->headers->set('Access-Control-Allow-Origin', 'https://other.example.com');

			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertSame('https://other.example.com', $response->headers->get('Access-Control-Allow-Origin'));
			$this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
		}

		public function testPartialPreExistingHeadersWithoutAllowOriginDoNotStopOrGetOverwritten(): void {
			$policy = $this->makePolicy([
				'allowed_origins'   => ['https://app.example.com'],
				'allow_credentials' => true,
			]);
			$response = new Response();
			$response->headers->set('Access-Control-Allow-Credentials', 'false');

			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertSame('https://app.example.com', $response->headers->get('Access-Control-Allow-Origin'));
			$this->assertSame('false', $response->headers->get('Access-Control-Allow-Credentials'));
		}

		// --- Vary merging -------------------------------------------------------------------

		public function testVaryMergingPreservesExistingUnrelatedValues(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response();
			$response->headers->set('Vary', 'Accept-Encoding');

			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertSame('Accept-Encoding, Origin', $response->headers->get('Vary'));
		}

		public function testVaryMergingIsIdempotentUnderRepeatedApplication(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response();

			$policy->apply($this->actualRequest('https://app.example.com'), $response);
			$policy->apply($this->actualRequest('https://app.example.com'), $response);

			$this->assertSame('Origin', $response->headers->get('Vary'));
		}

		// --- Preflight: approval ---------------------------------------------------------

		public function testApprovedPreflightReturnsFullConfiguredMethodAndHeaderListsBeyondWhatWasRequested(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['GET', 'POST', 'DELETE'],
				'allowed_headers' => ['Content-Type', 'Authorization'],
				'max_age'         => 600,
			]);
			$response = new Response();
			$response->headers->set('Allow', 'GET, POST, DELETE, PATCH');

			$request = $this->preflightRequest('https://app.example.com', 'POST', 'Content-Type');
			$policy->apply($request, $response);

			$this->assertSame('https://app.example.com', $response->headers->get('Access-Control-Allow-Origin'));

			// Full intersection of configured methods and the route's Allow, not
			// reduced to the single requested method.
			$this->assertSame('GET, POST, DELETE', $response->headers->get('Access-Control-Allow-Methods'));

			// Full configured header list, not reduced to the single requested header.
			$this->assertSame('Content-Type, Authorization', $response->headers->get('Access-Control-Allow-Headers'));

			$this->assertSame('600', $response->headers->get('Access-Control-Max-Age'));
		}

		public function testApprovedPreflightOmitsMaxAgeWhenNotPositive(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['GET'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'GET');

			$policy->apply($this->preflightRequest('https://app.example.com', 'GET'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Max-Age'));
		}

		public function testApprovedPreflightMatchesRequestedHeadersCaseInsensitively(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['POST'],
				'allowed_headers' => ['Content-Type', 'X-Custom'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'POST');

			$request = $this->preflightRequest('https://app.example.com', 'POST', 'content-type, X-CUSTOM');
			$policy->apply($request, $response);

			$this->assertSame('https://app.example.com', $response->headers->get('Access-Control-Allow-Origin'));
		}

		public function testApprovedPreflightMergesTheThreePreflightVaryNames(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['POST'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'POST');

			$policy->apply($this->preflightRequest('https://app.example.com', 'POST'), $response);

			$this->assertSame(
				'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
				$response->headers->get('Vary')
			);
		}

		// --- Preflight: rejection ---------------------------------------------------------

		public function testPreflightWithDisallowedOriginGrantsNothing(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['POST'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'POST');

			$policy->apply($this->preflightRequest('https://evil.example.com', 'POST'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
			$this->assertSame('POST', $response->headers->get('Allow'));
		}

		public function testPreflightWithNoAllowHeaderGrantsNothingEvenIfOriginAndMethodAreAllowed(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['POST'],
			]);
			$response = new Response();
			// No Allow header - an explicit OPTIONS route that supplied none.

			$policy->apply($this->preflightRequest('https://app.example.com', 'POST'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
		}

		public function testPreflightWithAMethodNotInTheRouteAllowHeaderGrantsNothing(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['GET', 'POST'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'GET');

			$policy->apply($this->preflightRequest('https://app.example.com', 'POST'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
		}

		public function testPreflightWithAMethodNotInTheConfiguredAllowlistGrantsNothing(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['GET'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'GET, POST');

			$policy->apply($this->preflightRequest('https://app.example.com', 'POST'), $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
		}

		public function testPreflightWithARequestedHeaderNotInTheConfiguredAllowlistGrantsNothing(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['POST'],
				'allowed_headers' => ['Content-Type'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'POST');

			$request = $this->preflightRequest('https://app.example.com', 'POST', 'Content-Type, X-Unlisted');
			$policy->apply($request, $response);

			$this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
		}

		public function testRejectedPreflightKeepsExistingStatusAndAllowHeader(): void {
			$policy = $this->makePolicy(['allowed_origins' => ['https://app.example.com']]);
			$response = new Response('', 204);
			$response->headers->set('Allow', 'GET');

			$policy->apply($this->preflightRequest('https://evil.example.com', 'GET'), $response);

			$this->assertSame(204, $response->getStatusCode());
			$this->assertSame('GET', $response->headers->get('Allow'));
		}

		public function testPreflightWithExistingAccessControlAllowOriginIsTreatedAsRouteOwned(): void {
			$policy = $this->makePolicy([
				'allowed_origins' => ['https://app.example.com'],
				'allowed_methods' => ['POST'],
			]);
			$response = new Response();
			$response->headers->set('Allow', 'POST');
			$response->headers->set('Access-Control-Allow-Origin', 'https://other.example.com');

			$policy->apply($this->preflightRequest('https://app.example.com', 'POST'), $response);

			$this->assertSame('https://other.example.com', $response->headers->get('Access-Control-Allow-Origin'));
			$this->assertFalse($response->headers->has('Access-Control-Allow-Methods'));
		}
	}
