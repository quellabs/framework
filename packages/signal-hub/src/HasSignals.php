<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Signal trait - can be used by classes to easily define signals
	 *
	 * This trait provides a convenient way for classes to implement the signal-slot pattern,
	 * allowing objects to emit signals and connect to other objects' slots for event handling.
	 * It manages signal creation, connection, disconnection, and emission automatically.
	 */
	trait HasSignals {
		
		/**
		 * Default signal hub for this object
		 * @var SignalHub|null
		 */
		private ?SignalHub $__signalHub = null;
		
		/**
		 * List of named signals
		 * @var array<string, Signal>
		 */
		private array $__signals = [];
		
		/**
		 * Get the default signal hub for this object
		 * @return SignalHub|null The default signal hub
		 */
		protected function getSignalHub(): ?SignalHub {
			return $this->__signalHub;
		}
		
		/**
		 * Set the default signal hub for this object
		 * @param SignalHub|null $hub The signal hub to use as default
		 * @return void
		 */
		protected function setSignalHub(?SignalHub $hub): void {
			$this->__signalHub = $hub;
		}
		
		/**
		 * Creates a new signal with the specified parameter types and optionally registers
		 * it with a signal hub. If a name is provided and a hub is available, the signal
		 * will be automatically registered for centralized management.
		 * @param array $parameterTypes Parameter types for the signal (e.g., ['string', 'int'])
		 * @param string|null $name Signal name for identification, null for anonymous signals
		 * @return Signal The created signal instance
		 */
		protected function createSignal(array $parameterTypes, ?string $name=null): Signal {
			// Create new signal with this object as the emitter
			$signal = new Signal($parameterTypes, $name, $this);
			
			// Add signal to register
			if ($name !== null) {
				$this->__signals[$name] = $signal;
			}
			
			// Register named signals with the hub for centralized management
			if ($name !== null && $this->__signalHub !== null) {
				$this->__signalHub->registerSignal($signal);
			}
			
			return $signal;
		}

		/**
		 * Get all named signals created by this object
		 * @return array Array of Signal objects indexed by signal name
		 */
		public function getSignals(): array {
			return $this->__signals;
		}
		
		/**
		 * Returns true if the object has the named signal, false if not
		 * @param string $name
		 * @return bool
		 */
		protected function hasSignal(string $name): bool {
			return isset($this->__signals[$name]);
		}
		
		/**
		 * Retrieves the signal stored with the given name.
		 * @param string $name Name of the signal
		 * @return Signal|null The signal if found, null otherwise
		 */
		public function getSignal(string $name): ?Signal {
			return $this->__signals[$name] ?? null;
		}
	}