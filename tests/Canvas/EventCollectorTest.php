<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Inspector\EventCollector;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHub;

	/**
	 * Tests which signals EventCollector records.
	 */
	class EventCollectorTest extends TestCase {

		/**
		 * Debug signals registered before the collector exists are still collected.
		 * @return void
		 */
		public function testCollectsDebugSignalsRegisteredBeforeConstruction(): void {
			$hub = new SignalHub();
			$canvasSignal = new Signal('debug.canvas.query');
			$databaseSignal = new Signal('debug.database.query');
			$hub->registerSignal($canvasSignal);
			$hub->registerSignal($databaseSignal);

			$collector = new EventCollector($hub);
			$canvasSignal->emit(['source' => 'canvas']);
			$databaseSignal->emit(['source' => 'database']);

			$this->assertSame(['debug.canvas.query', 'debug.database.query'], array_column($collector->getEvents(), 'signal'));
		}

		/**
		 * Debug signals registered after the collector exists are collected.
		 * @return void
		 */
		public function testCollectsDebugSignalsRegisteredAfterConstruction(): void {
			$hub = new SignalHub();
			$collector = new EventCollector($hub);

			$signal = new Signal('debug.database.query');
			$hub->registerSignal($signal);
			$signal->emit(['source' => 'database']);

			$this->assertSame(['debug.database.query'], array_column($collector->getEvents(), 'signal'));
		}

		/**
		 * Non-debug signals are ignored, whether registered before or after construction.
		 * @return void
		 */
		public function testIgnoresNonDebugSignals(): void {
			$hub = new SignalHub();
			$before = new Signal('app.booted');
			$hub->registerSignal($before);

			$collector = new EventCollector($hub);

			$after = new Signal('app.shutdown');
			$hub->registerSignal($after);

			$before->emit(['x' => 1]);
			$after->emit(['x' => 2]);

			$this->assertSame([], $collector->getEvents());
		}
	}
