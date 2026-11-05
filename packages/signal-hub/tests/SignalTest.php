<?php

	namespace Quellabs\SignalHub\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\Slot;

	/**
	 * Unit tests for Signal: connection management and emission.
	 */
	class SignalTest extends TestCase {

		/**
		 * A Signal is unnamed by default.
		 * @return void
		 */
		public function testGetNameReturnsNullWhenNoneWasGiven(): void {
			$signal = new Signal();

			$this->assertNull($signal->getName());
		}

		/**
		 * The constructor's name argument is returned unchanged.
		 * @return void
		 */
		public function testGetNameReturnsTheConstructorArgument(): void {
			$signal = new Signal('app.booted');

			$this->assertSame('app.booted', $signal->getName());
		}

		/**
		 * emit() invokes every connected Slot with the emitted arguments.
		 * @return void
		 */
		public function testEmitInvokesConnectedSlotsWithArguments(): void {
			$signal = new Signal();
			$received = [];

			$signal->connect(new Slot(function (string $value) use (&$received) {
				$received[] = $value;
			}));

			$signal->emit('payload');

			$this->assertSame(['payload'], $received);
		}

		/**
		 * emit() forwards zero or multiple arguments unchanged.
		 * @return void
		 */
		public function testEmitForwardsMultipleArguments(): void {
			$signal = new Signal();
			$received = null;

			$signal->connect(new Slot(function (int $a, int $b, int $c) use (&$received) {
				$received = [$a, $b, $c];
			}));

			$signal->emit(1, 2, 3);

			$this->assertSame([1, 2, 3], $received);
		}

		/**
		 * emit() on a Signal with no connections is a no-op, not an error.
		 * @return void
		 */
		public function testEmitWithNoConnectionsDoesNothing(): void {
			$signal = new Signal();

			$signal->emit('whatever');

			$this->assertTrue(true);
		}

		/**
		 * isConnected() reflects connect()/disconnect() state.
		 * @return void
		 */
		public function testIsConnectedReflectsConnectionState(): void {
			$signal = new Signal();
			$slot = new Slot(function () {
			});

			$this->assertFalse($signal->isConnected($slot));

			$signal->connect($slot);
			$this->assertTrue($signal->isConnected($slot));

			$signal->disconnect($slot);
			$this->assertFalse($signal->isConnected($slot));
		}

		/**
		 * disconnect() on a Slot that was never connected returns false
		 * instead of throwing.
		 * @return void
		 */
		public function testDisconnectingAnUnconnectedSlotReturnsFalse(): void {
			$signal = new Signal();
			$slot = new Slot(function () {
			});

			$this->assertFalse($signal->disconnect($slot));
		}

		/**
		 * disconnect() on a connected Slot removes it and returns true.
		 * @return void
		 */
		public function testDisconnectingAConnectedSlotReturnsTrue(): void {
			$signal = new Signal();
			$slot = new Slot(function () {
			});

			$signal->connect($slot);

			$this->assertTrue($signal->disconnect($slot));
			$this->assertFalse($signal->isConnected($slot));
		}

		/**
		 * countConnections() reflects the number of distinct connected Slots.
		 * @return void
		 */
		public function testCountConnectionsReflectsNumberOfSlots(): void {
			$signal = new Signal();

			$this->assertSame(0, $signal->countConnections());

			$signal->connect(new Slot(function () {
			}));
			$signal->connect(new Slot(function () {
			}));

			$this->assertSame(2, $signal->countConnections());
		}

		/**
		 * Connecting the same Slot twice does not create a duplicate
		 * invocation on emit().
		 * @return void
		 */
		public function testConnectingTheSameSlotTwiceDoesNotDuplicateInvocation(): void {
			$signal = new Signal();
			$calls = 0;
			$slot = new Slot(function () use (&$calls) {
				$calls++;
			});

			$signal->connect($slot);
			$signal->connect($slot);

			$this->assertSame(1, $signal->countConnections());

			$signal->emit();

			$this->assertSame(1, $calls);
		}

		/**
		 * Reconnecting an already-connected Slot with a different priority
		 * updates that priority rather than adding a second entry.
		 * @return void
		 */
		public function testReconnectingASlotUpdatesItsPriority(): void {
			$signal = new Signal();
			$order = [];

			$slot = new Slot(function () use (&$order) {
				$order[] = 'reconnected';
			});

			$other = new Slot(function () use (&$order) {
				$order[] = 'other';
			});

			$signal->connect($slot, priority: -10);
			$signal->connect($other, priority: 0);

			// Reconnecting with a higher priority must move it ahead of $other.
			$signal->connect($slot, priority: 10);
			$signal->emit();

			$this->assertSame(['reconnected', 'other'], $order);
		}

		/**
		 * Slots run in descending priority order; higher priority runs first.
		 * @return void
		 */
		public function testSlotsRunInDescendingPriorityOrder(): void {
			$signal = new Signal();
			$order = [];

			$signal->connect(new Slot(function () use (&$order) {
				$order[] = 'low';
			}), priority: -10);

			$signal->connect(new Slot(function () use (&$order) {
				$order[] = 'high';
			}), priority: 10);

			$signal->connect(new Slot(function () use (&$order) {
				$order[] = 'medium';
			}), priority: 0);

			$signal->emit();

			$this->assertSame(['high', 'medium', 'low'], $order);
		}

		/**
		 * Slots connected with equal priority run in connection order.
		 * @return void
		 */
		public function testEqualPrioritySlotsRunInConnectionOrder(): void {
			$signal = new Signal();
			$order = [];

			$signal->connect(new Slot(function () use (&$order) {
				$order[] = 'first';
			}));

			$signal->connect(new Slot(function () use (&$order) {
				$order[] = 'second';
			}));

			$signal->connect(new Slot(function () use (&$order) {
				$order[] = 'third';
			}));

			$signal->emit();

			$this->assertSame(['first', 'second', 'third'], $order);
		}

		/**
		 * A Slot may safely disconnect itself during its own invocation.
		 * The current emit() call still reaches every Slot that was
		 * connected when emit() started, and the self-disconnected Slot
		 * is not invoked again on a later emit().
		 * @return void
		 */
		public function testSlotMaySafelyDisconnectItselfDuringEmission(): void {
			$signal = new Signal();
			$order = [];
			$selfDisconnecting = null;

			$selfDisconnecting = new Slot(function () use (&$order, $signal, &$selfDisconnecting) {
				$order[] = 'self';
				$signal->disconnect($selfDisconnecting);
			});

			$signal->connect($selfDisconnecting, priority: 10);
			$signal->connect(new Slot(function () use (&$order) {
				$order[] = 'other';
			}), priority: 0);

			$signal->emit();

			$this->assertSame(['self', 'other'], $order);
			$this->assertFalse($signal->isConnected($selfDisconnecting));

			$order = [];
			$signal->emit();

			$this->assertSame(['other'], $order);
		}

		/**
		 * The same Slot instance can be connected to two different Signals
		 * at once, each tracking its own priority for that connection.
		 * @return void
		 */
		public function testSameSlotCanBeConnectedToMultipleSignals(): void {
			$calls = 0;
			$slot = new Slot(function () use (&$calls) {
				$calls++;
			});

			$signalA = new Signal();
			$signalB = new Signal();

			$signalA->connect($slot, priority: 5);
			$signalB->connect($slot, priority: 10);

			$signalA->disconnect($slot);

			$this->assertFalse($signalA->isConnected($slot));
			$this->assertTrue($signalB->isConnected($slot));

			$signalB->emit();

			$this->assertSame(1, $calls);
		}
	}
