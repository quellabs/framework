<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\MediumSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeaderPolicyInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	class MediumSecurityHeaderPolicyTest extends TestCase {

		public function testImplementsThePolicyInterface(): void {
			$this->assertInstanceOf(SecurityHeaderPolicyInterface::class, new MediumSecurityHeaderPolicy());
		}

		public function testDefaultsAreStricterThanTheBasicPolicy(): void {
			$policy = new MediumSecurityHeaderPolicy();
			$response = new Response();

			$policy->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
			$this->assertSame('strict-origin', $response->headers->get('Referrer-Policy'));
			$this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
		}

		public function testHstsDefaultsToTwoYearsWithoutPreload(): void {
			$policy = new MediumSecurityHeaderPolicy();
			$request = Request::create('https://example.test/');
			$request->server->set('HTTPS', 'on');
			$response = new Response();

			$policy->apply($request, $response);

			$this->assertSame('max-age=63072000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
		}

		public function testExistingHeadersAreNeverOverwritten(): void {
			$policy = new MediumSecurityHeaderPolicy();
			$response = new Response();
			$response->headers->set('Content-Security-Policy', "default-src 'none'");

			$policy->apply(Request::create('/'), $response);

			$this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
		}
	}
