<?php

	namespace Quellabs\SignalHub\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\SignalHub\Slot;

	/**
	 * Unit tests for Slot: a thin wrapper that gives a callable stable
	 * object identity so Signal can key connections on it.
	 */
	class SlotTest extends TestCase {

		/**
		 * invoke() forwards all arguments to the wrapped closure.
		 * @return void
		 */
		public function testInvokeForwardsArgumentsToClosure(): void {
			$received = null;

			$slot = new Slot(function (string $a, int $b) use (&$received) {
				$received = [$a, $b];
			});

			$slot->invoke('hello', 42);

			$this->assertSame(['hello', 42], $received);
		}

		/**
		 * invoke() works with an array callable, e.g. [$object, 'method'].
		 * @return void
		 */
		public function testInvokeSupportsArrayCallable(): void {
			$receiver = new SlotTestReceiver();
			$slot = new Slot([$receiver, 'handle']);

			$slot->invoke('payload');

			$this->assertSame(['payload'], $receiver->calls);
		}

		/**
		 * Two Slot instances wrapping the same closure expression are
		 * distinct objects, which is exactly why Signal keys on the Slot
		 * rather than on the callable itself.
		 * @return void
		 */
		public function testTwoSlotsWrappingEquivalentClosuresAreDistinctObjects(): void {
			$closure = function () {
			};

			$slotA = new Slot($closure);
			$slotB = new Slot($closure);

			$this->assertNotSame($slotA, $slotB);
		}
	}

	/**
	 * Plain receiver used to verify Slot::invoke() with an array callable.
	 */
	class SlotTestReceiver {

		/**
		 * @var array<int, mixed> Arguments passed to the most recent call
		 */
		public array $calls = [];

		/**
		 * Records the arguments it was invoked with.
		 * @param mixed $payload
		 * @return void
		 */
		public function handle(mixed $payload): void {
			$this->calls[] = $payload;
		}
	}
