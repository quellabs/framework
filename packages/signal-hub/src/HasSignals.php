<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Signal trait - can be used by classes to easily define signals
	 */
	trait HasSignals {
		/**
		 * @var array<string, Signal> Signals defined on this object
		 */
		private array $signals = [];
		
		/**
		 * @var SignalHub|null The SignalHub this object is registered with
		 */
		private ?SignalHub $signalHub = null;
		
		/**
		 * Define a signal for this object
		 * @param string $name Signal name
		 * @param array $parameterTypes Parameter types for the signal
		 * @return Signal The created signal
		 */
		protected function createSignal(string $name, array $parameterTypes): Signal {
			$signal = new Signal($parameterTypes, $name, $this);
			$this->signals[$name] = $signal;
			
			// Register signals with hub if available
			if ($this->signalHub !== null) {
				$this->signalHub->registerSignal($this, $name, $signal);
			}
			
			return $signal;
		}
		
		/**
		 * Register this object with a SignalHub
		 * @param SignalHub|null $hub
		 * @return void
		 */
		protected function registerWithHub(?SignalHub $hub = null): void {
			$this->signalHub = $hub ?? SignalHubLocator::getInstance();
			
			// Register signals as before
			foreach ($this->signals as $name => $signal) {
				$this->signalHub->registerSignal($this, $name, $signal);
			}
		}
		
		/**
		 * Get a defined signal by name
		 * @param string $name Signal name
		 * @return Signal|null The signal, or null if not found
		 */
		public function signal(string $name): ?Signal {
			return $this->signals[$name] ?? null;
		}
		
		/**
		 * Get all signals defined on this object
		 * @return array<string, Signal>
		 */
		public function getSignals(): array {
			return $this->signals;
		}
		
		/**
		 * Emit a signal by name
		 * @param string $name Signal name
		 * @param mixed ...$args Arguments to pass
		 * @throws \Exception If signal doesn't exist or argument mismatch
		 */
		protected function emit(string $name, ...$args): void {
			if (!isset($this->signals[$name])) {
				throw new \Exception("Signal '{$name}' does not exist.");
			}
			
			// Simply emit the signal directly - no hub relay needed anymore
			$this->signals[$name]->emit(...$args);
		}
	}