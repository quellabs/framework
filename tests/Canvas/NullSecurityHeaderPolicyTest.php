<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\Policies\NullSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeaderPolicyInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for NullSecurityHeaderPolicy, the explicit opt-out
	 * SecurityHeaderPolicyInterface implementation.
	 */
	class NullSecurityHeaderPolicyTest extends TestCase {

		public function testImplementsThePolicyInterface(): void {
			$this->assertInstanceOf(SecurityHeaderPolicyInterface::class, new NullSecurityHeaderPolicy());
		}

		public function testAddsNoHeaders(): void {
			$policy = new NullSecurityHeaderPolicy();
			$response = new Response();

			$policy->apply(Request::create('https://example.test/'), $response);

			$this->assertFalse($response->headers->has('X-Frame-Options'));
			$this->assertFalse($response->headers->has('X-Content-Type-Options'));
			$this->assertFalse($response->headers->has('Referrer-Policy'));
			$this->assertFalse($response->headers->has('Content-Security-Policy'));
			$this->assertFalse($response->headers->has('Strict-Transport-Security'));
		}
	}
