<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\RateLimitAspect;
	use Quellabs\Contracts\Cache\CacheInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for RateLimitAspect::after(), which applies the headers
	 * before() computed and stored on the request's attributes.
	 */
	class RateLimitAspectHeaderPropagationTest extends TestCase {

		private function makeAspect(): RateLimitAspect {
			$cache = $this->createStub(CacheInterface::class);
			return new RateLimitAspect($cache);
		}

		private function makeContext(Request $request): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			return $context;
		}

		public function testNoHeadersAttributeIsANoOp(): void {
			$response = new Response();

			$this->makeAspect()->after($this->makeContext(Request::create('/')), $response);

			$this->assertSame([], $response->headers->all('x-ratelimit-limit'));
		}

		public function testNonArrayHeadersAttributeIsSkipped(): void {
			$request = Request::create('/');
			$request->attributes->set('rate_limit_headers', 'not-an-array');
			$response = new Response();

			$this->makeAspect()->after($this->makeContext($request), $response);

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

			$this->makeAspect()->after($this->makeContext($request), $response);

			$this->assertSame('60', $response->headers->get('X-RateLimit-Limit'));
			$this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));
			$this->assertSame('1700000000', $response->headers->get('X-RateLimit-Reset'));
		}

		public function testRetryAfterIsAppliedWhenExceeded(): void {
			$request = Request::create('/');
			$request->attributes->set('rate_limit_headers', [
				'X-RateLimit-Limit'     => 10,
				'X-RateLimit-Remaining' => 0,
				'X-RateLimit-Reset'     => 1700000000,
				'Retry-After'           => 42,
			]);
			$response = new Response('Too Many Requests', 429);

			$this->makeAspect()->after($this->makeContext($request), $response);

			$this->assertSame('42', $response->headers->get('Retry-After'));
		}
	}
