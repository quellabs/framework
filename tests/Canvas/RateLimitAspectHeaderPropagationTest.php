<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\RateLimitAspect;
	use Quellabs\Contracts\Cache\CacheInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for RateLimitAspect::applyHeadersFromRequest(), the static helper
	 * extracted so ResponseFinalizer can apply rate-limit headers wherever the
	 * originating request is available, independent of whether after() ever ran.
	 */
	class RateLimitAspectHeaderPropagationTest extends TestCase {

		private function makeContext(Request $request): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			return $context;
		}

		public function testNoHeadersAttributeIsANoOp(): void {
			$response = new Response();

			RateLimitAspect::applyHeadersFromRequest(Request::create('/'), $response);

			$this->assertSame([], $response->headers->all('x-ratelimit-limit'));
		}

		public function testNonArrayHeadersAttributeIsSkipped(): void {
			$request = Request::create('/');
			$request->attributes->set('rate_limit_headers', 'not-an-array');
			$response = new Response();

			RateLimitAspect::applyHeadersFromRequest($request, $response);

			$this->assertFalse($response->headers->has('X-RateLimit-Limit'));
		}

		public function testArrayHeadersAttributeIsAppliedAsStrings(): void {
			$request = Request::create('/');
			$request->attributes->set('rate_limit_headers', [
				'X-RateLimit-Limit'     => 60,
				'X-RateLimit-Remaining' => 59,
				'X-RateLimit-Reset'     => 1700000000,
			]);
			$response = new Response();

			RateLimitAspect::applyHeadersFromRequest($request, $response);

			$this->assertSame('60', $response->headers->get('X-RateLimit-Limit'));
			$this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));
			$this->assertSame('1700000000', $response->headers->get('X-RateLimit-Reset'));
		}

		public function testAfterDelegatesToTheStaticHelper(): void {
			$cache = $this->createStub(CacheInterface::class);
			$aspect = new RateLimitAspect($cache);

			$request = Request::create('/');
			$request->attributes->set('rate_limit_headers', ['X-RateLimit-Limit' => 5]);
			$response = new Response();

			$aspect->after($this->makeContext($request), $response);

			$this->assertSame('5', $response->headers->get('X-RateLimit-Limit'));
		}
	}
