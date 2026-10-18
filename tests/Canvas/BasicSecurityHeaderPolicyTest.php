<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeaderPolicyInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for BasicSecurityHeaderPolicy, the basic built-in SecurityHeaderPolicyInterface
	 * implementation shared between SecurityHeadersAspect (per-route) and DefaultErrorHandler
	 * (application-wide) as their default.
	 */
	class BasicSecurityHeaderPolicyTest extends TestCase {

		public function testImplementsThePolicyInterface(): void {
			$this->assertInstanceOf(SecurityHeaderPolicyInterface::class, new BasicSecurityHeaderPolicy());
		}

		public function testDefaultsAddTheExpectedHeaders(): void {
			$policy = new BasicSecurityHeaderPolicy();
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
			$this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
			$this->assertFalse($response->headers->has('X-XSS-Protection'));
			$this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
			$this->assertFalse($response->headers->has('Content-Security-Policy'));
			$this->assertFalse($response->headers->has('Strict-Transport-Security'));
		}

		public function testHstsIsOnlyAddedOverHttps(): void {
			$policy = new BasicSecurityHeaderPolicy(hstsMaxAge: 1000);

			$httpResponse = new Response();
			$policy->apply(Request::create('http://example.test/'), $httpResponse);
			$this->assertFalse($httpResponse->headers->has('Strict-Transport-Security'));

			$httpsRequest = Request::create('https://example.test/');
			$httpsRequest->server->set('HTTPS', 'on');
			$httpsResponse = new Response();
			$policy->apply($httpsRequest, $httpsResponse);
			$this->assertSame('max-age=1000', $httpsResponse->headers->get('Strict-Transport-Security'));
		}

		public function testHstsPreloadIsAppendedWhenEnabled(): void {
			$policy = new BasicSecurityHeaderPolicy(hstsMaxAge: 1000, hstsPreload: true);

			$request = Request::create('https://example.test/');
			$request->server->set('HTTPS', 'on');
			$response = new Response();

			$policy->apply($request, $response);

			$this->assertSame('max-age=1000; preload', $response->headers->get('Strict-Transport-Security'));
		}

		public function testZeroHstsMaxAgeDisablesHsts(): void {
			$policy = new BasicSecurityHeaderPolicy(hstsMaxAge: 0);

			$request = Request::create('https://example.test/');
			$request->server->set('HTTPS', 'on');
			$response = new Response();

			$policy->apply($request, $response);

			$this->assertFalse($response->headers->has('Strict-Transport-Security'));
		}

		public function testCspIsAddedOnlyWhenConfigured(): void {
			$policy = new BasicSecurityHeaderPolicy(csp: "default-src 'self'");
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
		}

		public function testExistingHeadersAreNeverOverwritten(): void {
			$policy = new BasicSecurityHeaderPolicy(frameOptions: 'DENY', referrerPolicy: 'no-referrer');
			$response = new Response();
			$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
			$response->headers->set('Referrer-Policy', 'origin');

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
			$this->assertSame('origin', $response->headers->get('Referrer-Policy'));
		}

	}
