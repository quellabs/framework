<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\SecurityHeaderPolicy;
	use Quellabs\Contracts\Configuration\ConfigurationInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for SecurityHeaderPolicy, the header-writing logic shared between
	 * SecurityHeadersAspect (per-route) and DefaultErrorHandler (application-wide).
	 */
	class SecurityHeaderPolicyTest extends TestCase {

		/**
		 * @param array<string, mixed> $values
		 */
		private function makeConfig(array $values): ConfigurationInterface {
			$config = $this->createMock(ConfigurationInterface::class);

			$config->method('get')->willReturnCallback(
				function (string $key, mixed $default = null) use ($values): mixed {
					return $values[$key] ?? $default;
				}
			);

			return $config;
		}

		public function testDefaultsAddTheExpectedHeaders(): void {
			$policy = new SecurityHeaderPolicy();
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
			$this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
			$this->assertSame('1; mode=block', $response->headers->get('X-XSS-Protection'));
			$this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
			$this->assertFalse($response->headers->has('Content-Security-Policy'));
			$this->assertFalse($response->headers->has('Strict-Transport-Security'));
		}

		public function testHstsIsOnlyAddedOverHttps(): void {
			$policy = new SecurityHeaderPolicy(hstsMaxAge: 1000, hstsIncludeSubdomains: true);

			$httpResponse = new Response();
			$policy->apply(Request::create('http://example.test/'), $httpResponse);
			$this->assertFalse($httpResponse->headers->has('Strict-Transport-Security'));

			$httpsRequest = Request::create('https://example.test/');
			$httpsRequest->server->set('HTTPS', 'on');
			$httpsResponse = new Response();
			$policy->apply($httpsRequest, $httpsResponse);
			$this->assertSame('max-age=1000; includeSubDomains', $httpsResponse->headers->get('Strict-Transport-Security'));
		}

		public function testZeroHstsMaxAgeDisablesHsts(): void {
			$policy = new SecurityHeaderPolicy(hstsMaxAge: 0);

			$request = Request::create('https://example.test/');
			$request->server->set('HTTPS', 'on');
			$response = new Response();

			$policy->apply($request, $response);

			$this->assertFalse($response->headers->has('Strict-Transport-Security'));
		}

		public function testCspIsAddedOnlyWhenConfigured(): void {
			$policy = new SecurityHeaderPolicy(csp: "default-src 'self'");
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
		}

		public function testExistingHeadersAreNeverOverwritten(): void {
			$policy = new SecurityHeaderPolicy(frameOptions: 'DENY', referrerPolicy: 'no-referrer');
			$response = new Response();
			$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
			$response->headers->set('Referrer-Policy', 'origin');

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
			$this->assertSame('origin', $response->headers->get('Referrer-Policy'));
		}

		public function testFromConfigReadsEachKeyWithFallback(): void {
			$config = $this->makeConfig([
				'frame_options'           => 'DENY',
				'no_sniff'                => false,
				'xss_protection'          => false,
				'hsts_max_age'            => 0,
				'hsts_include_subdomains' => false,
				'referrer_policy'         => 'no-referrer',
				'csp'                     => "default-src 'none'",
			]);

			$response = new Response();
			SecurityHeaderPolicy::fromConfig($config)->apply(Request::create('/'), $response);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
			$this->assertFalse($response->headers->has('X-Content-Type-Options'));
			$this->assertFalse($response->headers->has('X-XSS-Protection'));
			$this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
			$this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
		}

		public function testFromConfigFallsBackToDefaultsWhenKeysAreMissing(): void {
			$response = new Response();
			SecurityHeaderPolicy::fromConfig($this->makeConfig([]))->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
			$this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
		}
	}
