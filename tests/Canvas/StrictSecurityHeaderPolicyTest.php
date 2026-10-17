<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeaderPolicyInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	class StrictSecurityHeaderPolicyTest extends TestCase {

		public function testImplementsThePolicyInterface(): void {
			$this->assertInstanceOf(SecurityHeaderPolicyInterface::class, new StrictSecurityHeaderPolicy());
		}

		public function testDefaultsDenyFramingAndLeakNoReferrer(): void {
			$policy = new StrictSecurityHeaderPolicy();
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
			$this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
			$this->assertSame(
				"default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'",
				$response->headers->get('Content-Security-Policy')
			);
		}

		public function testHstsDefaultsToTwoYearsWithPreload(): void {
			$policy = new StrictSecurityHeaderPolicy();
			$request = Request::create('https://example.test/');
			$request->server->set('HTTPS', 'on');
			$response = new Response();

			$policy->apply($request, $response);

			$this->assertSame('max-age=63072000; includeSubDomains; preload', $response->headers->get('Strict-Transport-Security'));
		}

		public function testAddsTheModernIsolationHeaders(): void {
			$policy = new StrictSecurityHeaderPolicy();
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('geolocation=(), camera=(), microphone=()', $response->headers->get('Permissions-Policy'));
			$this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
			$this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
			$this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
		}

		public function testExistingIsolationHeadersAreNeverOverwritten(): void {
			$policy = new StrictSecurityHeaderPolicy();
			$response = new Response();
			$response->headers->set('Cross-Origin-Opener-Policy', 'unsafe-none');

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('unsafe-none', $response->headers->get('Cross-Origin-Opener-Policy'));
		}
	}
