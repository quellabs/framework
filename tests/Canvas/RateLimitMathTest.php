<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\RateLimit\RateLimitMath;

	/**
	 * Unit tests for RateLimitMath, the pure admission math shared by
	 * FileRateLimitStore and MemcachedRateLimitStore's CAS loop. Every case here
	 * uses an explicit $now instead of time(), so window boundaries and refill
	 * calculations are exact and reproducible.
	 */
	class RateLimitMathTest extends TestCase {

		// =========================================================================
		// fixedWindow
		// =========================================================================

		public function testFixedWindowFirstRequestIsAdmitted(): void {
			$admission = RateLimitMath::fixedWindow(null, 5, 60, 1000);

			$this->assertSame(['count' => 1], $admission->getState());
			$this->assertSame(1, $admission->getResult()->getCount());
			$this->assertFalse($admission->getResult()->isExceeded());
		}

		public function testFixedWindowAdmitsUpToTheLimitThenExceeds(): void {
			$state = null;

			for ($i = 1; $i <= 5; $i++) {
				$admission = RateLimitMath::fixedWindow($state, 5, 60, 1000);
				$state = $admission->getState();
				$this->assertFalse($admission->getResult()->isExceeded(), "request {$i} should be admitted");
			}

			$result = RateLimitMath::fixedWindow($state, 5, 60, 1000)->getResult();
			$this->assertTrue($result->isExceeded());
			$this->assertSame(6, $result->getCount());
		}

		public function testFixedWindowResetTimeIsTheWindowBoundaryNotNowPlusWindow(): void {
			// window=60, now=1005 -> windowStart=960, reset=1020 (not 1065)
			$result = RateLimitMath::fixedWindow(null, 5, 60, 1005)->getResult();

			$this->assertSame(1020, $result->getResetTime());
			$this->assertSame(15, $result->getRetryAfter());
		}

		public function testFixedWindowRollsOverToANewCounterInTheNextWindow(): void {
			$state = RateLimitMath::fixedWindow(null, 5, 60, 1005)->getState();

			// Still the same window (960-1020): state carries over.
			$admission2 = RateLimitMath::fixedWindow($state, 5, 60, 1010);
			$this->assertSame(2, $admission2->getResult()->getCount());

			// A fresh call for the *next* window must not reuse $admission2's count - the
			// store is responsible for keying by window, so simulate that boundary by
			// calling with state=null (as a new window's state file would start).
			$result3 = RateLimitMath::fixedWindow(null, 5, 60, 1021)->getResult();
			$this->assertSame(1, $result3->getCount());
		}

		// =========================================================================
		// slidingWindow
		// =========================================================================

		public function testSlidingWindowFirstRequestIsAdmitted(): void {
			$admission = RateLimitMath::slidingWindow(null, 5, 60, 1000);

			$this->assertSame(['timestamps' => [1000]], $admission->getState());
			$this->assertFalse($admission->getResult()->isExceeded());
		}

		public function testSlidingWindowExpiresTimestampsOutsideTheWindow(): void {
			$state = RateLimitMath::slidingWindow(null, 5, 60, 1000)->getState();

			// 61 seconds later, the first timestamp has rolled out of the window.
			$admission2 = RateLimitMath::slidingWindow($state, 5, 60, 1061);

			$this->assertSame(['timestamps' => [1061]], $admission2->getState());
			$this->assertSame(1, $admission2->getResult()->getCount());
		}

		public function testSlidingWindowExceedsAfterTheLimitWithinTheWindow(): void {
			$state = null;

			for ($i = 0; $i < 5; $i++) {
				$admission = RateLimitMath::slidingWindow($state, 5, 60, 1000 + $i);
				$state = $admission->getState();
				$this->assertFalse($admission->getResult()->isExceeded());
			}

			$result = RateLimitMath::slidingWindow($state, 5, 60, 1004)->getResult();
			$this->assertTrue($result->isExceeded());
			$this->assertSame(6, $result->getCount());
			// retry_after: oldest (1000) + window (60) - now (1004) = 56
			$this->assertSame(56, $result->getRetryAfter());
		}

		public function testSlidingWindowCapsTrackedTimestampsUnderSustainedAbuse(): void {
			$state = null;

			// Far more requests than the limit, all within the window: memory must stay bounded.
			for ($i = 0; $i < 500; $i++) {
				$state = RateLimitMath::slidingWindow($state, 5, 60, 1000)->getState();
			}

			$timestamps = $state['timestamps'];
			$this->assertIsArray($timestamps);
			$this->assertLessThanOrEqual(max(5 * 4, 100) + 1, count($timestamps));
		}

		// =========================================================================
		// tokenBucket
		// =========================================================================

		public function testTokenBucketStartsFullAndAdmitsUpToCapacity(): void {
			$state = null;

			for ($i = 1; $i <= 5; $i++) {
				$admission = RateLimitMath::tokenBucket($state, 5, 60, 1000);
				$state = $admission->getState();
				$this->assertFalse($admission->getResult()->isExceeded(), "request {$i} should be admitted");
			}

			$result = RateLimitMath::tokenBucket($state, 5, 60, 1000)->getResult();
			$this->assertTrue($result->isExceeded());
		}

		public function testTokenBucketRefillsOverTimeUpToCapacity(): void {
			$state = RateLimitMath::tokenBucket(null, 5, 60, 1000)->getState();

			// Drain the remaining 4 tokens.
			for ($i = 0; $i < 4; $i++) {
				$state = RateLimitMath::tokenBucket($state, 5, 60, 1000)->getState();
			}

			// Bucket should be empty now.
			$exhausted = RateLimitMath::tokenBucket($state, 5, 60, 1000)->getResult();
			$this->assertTrue($exhausted->isExceeded());

			// 12 seconds later at refill rate 5/60 = one token should be available.
			$refilled = RateLimitMath::tokenBucket($state, 5, 60, 1012)->getResult();
			$this->assertFalse($refilled->isExceeded());
		}

		public function testTokenBucketNeverExceedsCapacityEvenAfterLongIdlePeriod(): void {
			$state = RateLimitMath::tokenBucket(null, 5, 60, 1000)->getState();

			// A day later, the bucket must still cap at 5, not overflow.
			$result = RateLimitMath::tokenBucket($state, 5, 60, 1000 + 86400)->getResult();

			$this->assertFalse($result->isExceeded());
			$this->assertSame(1, $result->getCount());
		}
	}
