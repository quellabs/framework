<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\RateLimitException;
	use Quellabs\Canvas\Exceptions\RateLimitStoreUnavailableException;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\RateLimit\RateLimitResult;
	use Quellabs\Canvas\Security\RateLimit\RateLimitStoreInterface;
	use Quellabs\Canvas\Security\RateLimitAspect;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for RateLimitAspect against a mocked RateLimitStoreInterface.
	 *
	 * Admission itself is RateLimitStoreInterface's job (covered by
	 * RateLimitMathTest and FileRateLimitStoreTest, including a real multi-process
	 * concurrency test). What belongs here is the aspect's own contract: which
	 * store method each strategy calls, how a result becomes headers and request
	 * attributes, and - the actual point of this gap - what happens when the store
	 * itself fails (onStoreFailure policy), which an aspect-level test is the only
	 * place that can exercise without a real backend outage.
	 */
	class RateLimitAspectTest extends TestCase {

		private function makeContext(Request $request, string $class = 'App\\Controller', string $method = 'action'): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			$context->method('getClassName')->willReturn($class);
			$context->method('getMethodName')->willReturn($method);
			return $context;
		}

		public function testExemptMethodBypassesTheStoreEntirely(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->expects($this->never())->method('incrementFixedWindow');

			$aspect = new RateLimitAspect($store, exemptMethods: ['GET']);
			$request = Request::create('/', 'GET');

			$this->assertNull($aspect->before($this->makeContext($request)));
			$this->assertFalse($request->attributes->has('rate_limit_headers'));
		}

		public function testFixedWindowStrategyCallsIncrementFixedWindow(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->expects($this->once())
				->method('incrementFixedWindow')
				->with($this->isType('string'), 5, 60, $this->isType('int'))
				->willReturn(new RateLimitResult(1, false, 2000, 0));

			$aspect = new RateLimitAspect($store, limit: 5, window: 60, strategy: 'fixed_window');
			$aspect->before($this->makeContext(Request::create('/', 'POST')));
		}

		public function testSlidingWindowStrategyCallsAdmitSlidingWindow(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->expects($this->once())
				->method('admitSlidingWindow')
				->willReturn(new RateLimitResult(1, false, 2000, 0));

			$aspect = new RateLimitAspect($store, strategy: 'sliding_window');
			$aspect->before($this->makeContext(Request::create('/', 'POST')));
		}

		public function testTokenBucketStrategyCallsAdmitTokenBucket(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->expects($this->once())
				->method('admitTokenBucket')
				->willReturn(new RateLimitResult(1, false, 2000, 0));

			$aspect = new RateLimitAspect($store, strategy: 'token_bucket');
			$aspect->before($this->makeContext(Request::create('/', 'POST')));
		}

		public function testHeadersAndAttributesAreBuiltFromTheResult(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->method('incrementFixedWindow')->willReturn(new RateLimitResult(3, false, 1999999999, 0));

			$aspect = new RateLimitAspect($store, limit: 5, headerPrefix: 'X-RateLimit');
			$request = Request::create('/', 'POST');
			$aspect->before($this->makeContext($request));

			$headers = $request->attributes->get('rate_limit_headers');
			$this->assertIsArray($headers);
			$this->assertSame(5, $headers['X-RateLimit-Limit']);
			$this->assertSame(2, $headers['X-RateLimit-Remaining']);
			$this->assertSame(1999999999, $headers['X-RateLimit-Reset']);
			$this->assertArrayNotHasKey('Retry-After', $headers);
			$this->assertFalse($request->attributes->get('rate_limit_exceeded'));
		}

		public function testExceededResultAddsRetryAfterHeaderAndAttribute(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->method('incrementFixedWindow')->willReturn(new RateLimitResult(6, true, 1999999999, 42));

			$aspect = new RateLimitAspect($store, limit: 5);
			$request = Request::create('/', 'POST');
			$aspect->before($this->makeContext($request));

			$headers = $request->attributes->get('rate_limit_headers');
			$this->assertIsArray($headers);
			$this->assertSame(42, $headers['Retry-After']);
			$this->assertTrue($request->attributes->get('rate_limit_exceeded'));
		}

		public function testThrowOnFailureThrowsRateLimitExceptionWhenExceeded(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->method('incrementFixedWindow')->willReturn(new RateLimitResult(6, true, 1999999999, 42));

			$aspect = new RateLimitAspect($store, limit: 5, throwOnFailure: true);

			$this->expectException(RateLimitException::class);
			$aspect->before($this->makeContext(Request::create('/', 'POST')));
		}

		public function testThrowOnFailureDoesNotThrowWhenNotExceeded(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->method('incrementFixedWindow')->willReturn(new RateLimitResult(1, false, 1999999999, 0));

			$aspect = new RateLimitAspect($store, limit: 5, throwOnFailure: true);

			$this->assertNull($aspect->before($this->makeContext(Request::create('/', 'POST'))));
		}

		public function testAfterAppliesHeadersPreparedDuringBefore(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->method('incrementFixedWindow')->willReturn(new RateLimitResult(1, false, 1999999999, 0));

			$aspect = new RateLimitAspect($store, limit: 5);
			$request = Request::create('/', 'POST');
			$context = $this->makeContext($request);
			$aspect->before($context);

			$response = new Response();
			$aspect->after($context, $response);

			$this->assertSame('5', $response->headers->get('X-RateLimit-Limit'));
			$this->assertSame('4', $response->headers->get('X-RateLimit-Remaining'));
		}

		// =========================================================================
		// Store failure policy - the actual point of this gap
		// =========================================================================

		public function testDefaultOnStoreFailureIsClosedAndPropagatesTheOutage(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->method('incrementFixedWindow')->willThrowException(
				new RateLimitStoreUnavailableException('backend down')
			);

			$aspect = new RateLimitAspect($store);

			$this->expectException(RateLimitStoreUnavailableException::class);
			$aspect->before($this->makeContext(Request::create('/', 'POST')));
		}

		public function testOnStoreFailureOpenBypassesRateLimitingWithoutInventingAResult(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);
			$store->method('incrementFixedWindow')->willThrowException(
				new RateLimitStoreUnavailableException('backend down')
			);

			$aspect = new RateLimitAspect($store, onStoreFailure: 'open');
			$request = Request::create('/', 'POST');

			$this->assertNull($aspect->before($this->makeContext($request)));
			$this->assertFalse($request->attributes->has('rate_limit_headers'));
			$this->assertFalse($request->attributes->has('rate_limit_exceeded'));
		}

		public function testInvalidOnStoreFailureValueIsRejectedAtConstruction(): void {
			$store = $this->createMock(RateLimitStoreInterface::class);

			$this->expectException(\InvalidArgumentException::class);
			new RateLimitAspect($store, onStoreFailure: 'bogus');
		}

		// =========================================================================
		// Cache key isolation
		// =========================================================================

		public function testIdentifiersThatSanitizeToTheSameStringProduceDifferentKeys(): void {
			// Both 'a:b' and 'a/b' collapse to 'a_b' under the sanitizer's
			// disallowed-character replacement; they must still produce distinct keys.
			$keys = [];
			$capture = function (string $key) use (&$keys): RateLimitResult {
				$keys[] = $key;
				return new RateLimitResult(1, false, 2000, 0);
			};

			$storeA = $this->createMock(RateLimitStoreInterface::class);
			$storeA->method('incrementFixedWindow')->willReturnCallback($capture);
			(new RateLimitAspect($storeA, scope: 'custom', identifier: 'a:b'))
				->before($this->makeContext(Request::create('/', 'POST')));

			$storeB = $this->createMock(RateLimitStoreInterface::class);
			$storeB->method('incrementFixedWindow')->willReturnCallback($capture);
			(new RateLimitAspect($storeB, scope: 'custom', identifier: 'a/b'))
				->before($this->makeContext(Request::create('/', 'POST')));

			$this->assertCount(2, $keys);
			$this->assertNotSame($keys[0], $keys[1]);
		}
	}
