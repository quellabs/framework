<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Centralized registry for signals in the application, providing registration,
	 * discovery, and lifecycle management. Supports both standalone signals and
	 * object-owned signals with automatic memory management through WeakMap usage.
	 */
	class SignalHub {
		
		/**
		 * @var \WeakMap Map of objects to their signal collections
		 * Structure: WeakMap<object, array<string, Signal>>
		 */
		private \WeakMap $objectSignals;
		
		/**
		 * @var array Standalone signals not owned by any object
		 */
		private array $standaloneSignals = [];
		
		/**
		 * @var Signal Emitted when a signal is registered with the hub
		 */
		private Signal $signalRegisteredEvent;
		
		/**
		 * @var Signal Emitted when a signal is unregistered from the hub
		 */
		private Signal $signalUnregisteredEvent;
		
		/**
		 * SignalHub constructor
		 */
		public function __construct() {
			$this->objectSignals = new \WeakMap();
			$this->signalRegisteredEvent = new Signal('hub.signal.registered');
			$this->signalUnregisteredEvent = new Signal('hub.signal.unregistered');
		}
		
		/**
		 * Scan an object for Signal-typed properties and register them with the hub
		 * @param object $object Object to scan for signals
		 * @return void
		 */
		public function registerSignals(object $object): void {
			$reflection = new \ReflectionClass($object);
			
			foreach ($reflection->getProperties() as $property) {
				$type = $property->getType();
				
				if (!$type instanceof \ReflectionNamedType || $type->getName() !== Signal::class) {
					continue;
				}
				
				// Initialize uninitialized signal properties
				if (!$property->isInitialized($object)) {
					$property->setValue($object, new Signal($property->getName(), $object));
				}
				
				$signal = $property->getValue($object);
				
				if (!isset($this->objectSignals[$object])) {
					$this->objectSignals[$object] = [];
				}
				
				$this->objectSignals[$object][$property->getName()] = $signal;
				$this->signalRegisteredEvent->emit($signal);
			}
		}
		
		/**
		 * Unregister all signals for a given object
		 * @param object $object Object whose signals should be removed
		 * @return void
		 */
		public function unregisterSignals(object $object): void {
			if (!isset($this->objectSignals[$object])) {
				return;
			}
			
			foreach ($this->objectSignals[$object] as $signal) {
				$this->signalUnregisteredEvent->emit($signal);
			}
			
			unset($this->objectSignals[$object]);
		}
		
		/**
		 * Register a standalone signal (not owned by any object)
		 * @param Signal $signal Signal to register
		 * @return void
		 * @throws \RuntimeException If signal has no name or name is already taken
		 */
		public function registerSignal(Signal $signal): void {
			$name = $signal->getName();
			
			if ($name === null) {
				throw new \RuntimeException("Signal name is null");
			}
			
			if (isset($this->standaloneSignals[$name])) {
				throw new \RuntimeException("Standalone signal '{$name}' already registered");
			}
			
			$this->signalRegisteredEvent->emit($signal);
			$this->standaloneSignals[$name] = $signal;
		}
		
		/**
		 * Unregister a standalone signal
		 * @param Signal $signal Signal to unregister
		 * @return bool True if found and removed
		 */
		public function unregisterSignal(Signal $signal): bool {
			$name = $signal->getName();
			
			if ($name === null || !isset($this->standaloneSignals[$name])) {
				return false;
			}
			
			$this->signalUnregisteredEvent->emit($this->standaloneSignals[$name]);
			unset($this->standaloneSignals[$name]);
			return true;
		}
		
		/**
		 * Find a signal by name and optional owner
		 * @param string $name Signal name
		 * @param object|string|null $owner Optional owner object or class name string
		 * @return Signal|null
		 */
		public function getSignal(string $name, object|string|null $owner = null): ?Signal {
			if ($owner !== null) {
				if (is_string($owner)) {
					foreach ($this->objectSignals as $object => $signals) {
						if (get_class($object) === $owner) {
							return $signals[$name] ?? null;
						}
					}
					return null;
				}
				
				return $this->objectSignals[$owner][$name] ?? null;
			}
			
			foreach ($this->objectSignals as $signals) {
				if (isset($signals[$name])) {
					return $signals[$name];
				}
			}
			
			return $this->standaloneSignals[$name] ?? null;
		}
		
		/**
		 * Find signals matching a pattern, optionally filtering by owner
		 * @param string $pattern Signal name pattern with optional wildcards (*)
		 * @param object|null $owner Optional owner to filter by
		 * @return array<Signal> Matching signals keyed by signal name
		 */
		public function findSignals(string $pattern, ?object $owner = null): array {
			$results = [];
			
			if ($owner === null) {
				foreach ($this->standaloneSignals as $name => $signal) {
					if ($this->matchesPattern($pattern, $name)) {
						$results[$name] = $signal;
					}
				}
			}
			
			foreach ($this->objectSignals as $object => $signals) {
				if ($owner !== null && $object !== $owner) {
					continue;
				}
				
				foreach ($signals as $signalName => $signal) {
					if ($this->matchesPattern($pattern, $signalName)) {
						$results[$signalName] = $signal;
					}
				}
			}
			
			return $results;
		}
		
		/**
		 * Get the signal emitted when a signal is registered
		 * @return Signal
		 */
		public function signalRegistered(): Signal {
			return $this->signalRegisteredEvent;
		}
		
		/**
		 * Get the signal emitted when a signal is unregistered
		 * @return Signal
		 */
		public function signalUnregistered(): Signal {
			return $this->signalUnregisteredEvent;
		}
		
		/**
		 * Check if a name matches a wildcard pattern
		 * @param string $pattern Pattern with optional * wildcards
		 * @param string $name Name to test
		 * @return bool
		 */
		private function matchesPattern(string $pattern, string $name): bool {
			if (!str_contains($pattern, '*')) {
				return $pattern === $name;
			}
			
			$regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
			return (bool)preg_match($regex, $name);
		}
	}