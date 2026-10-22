<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\CompositeSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\NullSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Tests CompositeSecurityHeaderPolicy's ordered application of multiple policies
	 * to the same response.
	 */
	class CompositeSecurityHeaderPolicyTest extends TestCase {

		public function testAppliesEachPolicyInDeclaredOrder(): void {
			$composite = new CompositeSecurityHeaderPolicy([
				new StrictSecurityHeaderPolicy(),
				new BasicSecurityHeaderPolicy(frameOptions: 'ALLOW-FROM'),
			]);

			$response = new Response();
			$composite->apply(Request::create('/'), $response);

			// StrictSecurityHeaderPolicy runs first and sets X-Frame-Options to
			// DENY; BasicSecurityHeaderPolicy's addFrameOptions() only sets a
			// header when one isn't already present, so the first entry wins.
			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		}

		public function testReversingTheOrderChangesTheWinningValue(): void {
			$composite = new CompositeSecurityHeaderPolicy([
				new BasicSecurityHeaderPolicy(frameOptions: 'ALLOW-FROM'),
				new StrictSecurityHeaderPolicy(),
			]);

			$response = new Response();
			$composite->apply(Request::create('/'), $response);

			$this->assertSame('ALLOW-FROM', $response->headers->get('X-Frame-Options'));
		}

		public function testANullPolicyEntryDoesNotSuppressOtherEntries(): void {
			$composite = new CompositeSecurityHeaderPolicy([
				new NullSecurityHeaderPolicy(),
				new BasicSecurityHeaderPolicy(),
			]);

			$response = new Response();
			$composite->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
		}

		public function testAllNullPoliciesApplyNoHeaders(): void {
			$composite = new CompositeSecurityHeaderPolicy([
				new NullSecurityHeaderPolicy(),
				new NullSecurityHeaderPolicy(),
			]);

			$response = new Response();
			$composite->apply(Request::create('/'), $response);

			$this->assertFalse($response->headers->has('X-Frame-Options'));
		}
	}
