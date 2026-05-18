<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Slot is the receiving end of a signal-slot connection.
	 *
	 * A Slot wraps a callable and gives it stable object identity. That identity
	 * is what Signal uses internally to key connections — not the callable itself,
	 * which has unreliable equality semantics in PHP (closures, first-class callables,
	 * and invokable objects created from the same expression are distinct objects).
	 *
	 * Usage:
	 *
	 *   $slot = new Slot([$this, 'handlePrePersist']);
	 *   $signal->connect($slot);
	 *   $signal->disconnect($slot);
	 *
	 * A single Slot instance may be connected to multiple signals simultaneously.
	 * Each signal maintains its own priority for the connection independently.
	 *
	 * Lifetime management:
	 * Signals hold Slot references in a WeakMap. When the caller drops the last
	 * reference to a Slot, it is garbage collected and automatically removed from
	 * all connected signals without requiring an explicit disconnect() call.
	 * For deterministic removal, use Signal::disconnect() explicitly.
	 */
	class Slot {
		
		/**
		 * The callable this slot invokes when the signal fires.
		 * @var callable
		 */
		private $receiver;
		
		/**
		 * @param callable $receiver The callable to invoke when the signal is emitted
		 */
		public function __construct(callable $receiver) {
			$this->receiver = $receiver;
		}
		
		/**
		 * Invoke the receiver with the given arguments.
		 * Called internally by Signal::emit().
		 * @param mixed ...$args Arguments forwarded from the signal
		 * @return void
		 */
		public function invoke(mixed ...$args): void {
			($this->receiver)(...$args);
		}
	}