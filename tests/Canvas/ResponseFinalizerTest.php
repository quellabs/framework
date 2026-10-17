<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\ResponseFinalizer;
	use Quellabs\Canvas\Security\SecurityHeaderPolicy;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for ResponseFinalizer, the single point every response passes
	 * through in Kernel::handle() regardless of which path produced it (normal
	 * controller response, before-aspect short circuit, exception, route miss,
	 * or legacy response).
	 */
	class ResponseFinalizerTest extends TestCase {

		public function testNoPolicyAndNoRateLimitAttributesLeavesResponseUntouched(): void {
			$finalizer = new ResponseFinalizer(null);
			$request = Request::create('/missing');
			$response = new Response('not found', 404);

			$finalizer->finalize($request, $response);

			$this->assertSame('not found', $response->getContent());
			$this->assertSame(404, $response->getStatusCode());
			$this->assertFalse($response->headers->has('X-Frame-Options'));
		}

		public function testRateLimitHeadersAreAppliedEvenWithoutASecurityPolicy(): void {
			$finalizer = new ResponseFinalizer(null);
			$request = Request::create('/api/data');
			$request->attributes->set('rate_limit_headers', [
				'X-RateLimit-Limit'     => 60,
				'X-RateLimit-Remaining' => 59,
				'X-RateLimit-Reset'     => 1700000000,
			]);
			$response = new Response();

			$finalizer->finalize($request, $response);

			$this->assertSame('60', $response->headers->get('X-RateLimit-Limit'));
			$this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));
			$this->assertSame('1700000000', $response->headers->get('X-RateLimit-Reset'));
		}

		/**
		 * Exercises the case the plan calls out explicitly: a RateLimitException thrown
		 * with throwOnFailure=true still has its headers on the request (set before the
		 * throw), so the finalizer applies Retry-After to the resulting 429 response even
		 * though AspectDispatcher never reached RateLimitAspect::after().
		 */
		public function testRetryAfterSurvivesABeforeAspectShortCircuitOrException(): void {
			$finalizer = new ResponseFinalizer(null);
			$request = Request::create('/api/data');
			$request->attributes->set('rate_limit_headers', [
				'X-RateLimit-Limit'     => 10,
				'X-RateLimit-Remaining' => 0,
				'X-RateLimit-Reset'     => 1700000000,
				'Retry-After'           => 42,
			]);
			$response = new Response('Too Many Requests', 429);

			$finalizer->finalize($request, $response);

			$this->assertSame('42', $response->headers->get('Retry-After'));
			$this->assertSame(429, $response->getStatusCode());
		}

		public function testGlobalSecurityPolicyIsAppliedWhenConfigured(): void {
			$finalizer = new ResponseFinalizer(new SecurityHeaderPolicy(frameOptions: 'DENY'));
			$request = Request::create('/missing');
			$response = new Response('not found', 404);

			$finalizer->finalize($request, $response);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
			$this->assertSame(404, $response->getStatusCode());
		}

		public function testGlobalSecurityPolicyNeverOverwritesARouteSpecificHeader(): void {
			$finalizer = new ResponseFinalizer(new SecurityHeaderPolicy(frameOptions: 'DENY'));
			$request = Request::create('/secure');
			$response = new Response();
			$response->headers->set('X-Frame-Options', 'SAMEORIGIN');

			$finalizer->finalize($request, $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
		}

		public function testFinalizePreservesCookiesAndBody(): void {
			$finalizer = new ResponseFinalizer(new SecurityHeaderPolicy());
			$request = Request::create('/');
			$response = new Response('<p>hi</p>', 200);
			$response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('session', 'abc'));

			$finalizer->finalize($request, $response);

			$this->assertSame('<p>hi</p>', $response->getContent());
			$this->assertNotEmpty($response->headers->getCookies());
		}
	}
