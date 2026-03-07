<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Centralized registry for signals in the application, providing registration,
	 * discovery, and lifecycle management.
	 *
	 * Two kinds of signals are supported:
	 *
	 * - Object-owned signals: declared as typed `Signal` properties on a class.
	 *   Discovered automatically via reflection using discoverSignals(). Stored in
	 *   a WeakMap so they are garbage collected when the owning object goes out of scope.
	 *
	 * - Standalone signals: not tied to any object. Registered manually via registerSignal()
	 *   and looked up by name.
	 *
	 * The hub is the rendezvous point for consumers that need to connect to signals on
	 * objects they don't hold a direct reference to. For example, a service can connect
	 * to a signal on an auto-discovered controller using only its class name:
	 *
	 *   $hub->getSignal(MollieController::class, 'paymentPaid')->connect($handler);
	 *
	 * Only one instance per class may be registered at a time. If multiple instances of
	 * the same class are discovered, the class name lookup in getSignal() will return the
	 * first match, which may not be the intended one. Design your emitting classes so that
	 * only one instance is active at any given time.
	 */
	class SignalHub {
		
		/**
		 * @var \WeakMap<object, array<string, Signal>>
		 *
		 * Maps objects to their discovered signals. Using WeakMap means the hub does not
		 * prevent garbage collection — when an object is destroyed, its entry here is
		 * automatically removed without any manual cleanup.
		 */
		private \WeakMap $objectSignals;

		/**
		 * @var array<string, list<string>>
		 *
		 * Caches the names of Signal-typed properties per class, so reflection is only
		 * performed once per class regardless of how many instances are discovered.
		 */
		private array $signalPropertyCache = [];

		/**
		 * @var array<string, Signal>
		 *
		 * Standalone signals registered manually via registerSignal(). Keyed by signal name.
		 * Unlike object signals, these are not garbage collected automatically and must be
		 * removed explicitly via unregisterSignal() when no longer needed.
		 */
		private array $standaloneSignals = [];
		
		/**
		 * @var Signal Emitted when any signal is registered with or discovered by the hub.
		 * Receives the Signal object that was just registered as its argument.
		 */
		private Signal $signalRegisteredEvent;
		
		/**
		 * @var Signal Emitted when any signal is unregistered from the hub.
		 * Receives the Signal object that is about to be removed as its argument.
		 */
		private Signal $signalUnregisteredEvent;
		
		/**
		 * SignalHub constructor
		 */
		public function __construct() {
			$this->objectSignals = new \WeakMap();
			
			// Meta-signals are standalone signals owned by the hub itself.
			// They allow external code to monitor registration and unregistration activity,
			// useful for debugging, audit logging, or dynamic auto-connect patterns.
			$this->signalRegisteredEvent = new Signal('hub.signal.registered');
			$this->signalUnregisteredEvent = new Signal('hub.signal.unregistered');
		}

		/**
		 * Discover all Signal-typed properties on an object and register them with the hub.
		 * @param object $object Object to scan for Signal properties
		 * @return array List of found signals
		 * @throws \RuntimeException|\ReflectionException If the object was already discovered, or a Signal property is uninitialized
		 */
		public function discoverSignals(object $object): array {
			// Guard against double discovery — silent overwrite would mask bugs in the dispatcher
			if (isset($this->objectSignals[$object])) {
				throw new \RuntimeException(
					sprintf('Signals for "%s" are already registered', get_class($object))
				);
			}

			// Fetch class name
			$class = get_class($object);

			// Reflect only once per class — subsequent instances reuse the cached property names
			if (!isset($this->signalPropertyCache[$class])) {
				$this->signalPropertyCache[$class] = $this->resolveSignalProperties($class);
			}

			// Always create the entry, even if the class has no Signal properties,
			// so the double-discovery guard works correctly on subsequent calls
			$this->objectSignals[$object] = [];

			// Nothing to register if this class has no Signal properties
			if (empty($this->signalPropertyCache[$class])) {
				return [];
			}
			
			// Find and all register all signals
			foreach ($this->signalPropertyCache[$class] as $propertyName) {
				$property = new \ReflectionProperty($object, $propertyName);

				// Signal properties must be initialized before discovery — the hub is a registry,
				// not a factory. If this throws, the owning class forgot to initialize the property.
				if (!$property->isInitialized($object)) {
					throw new \RuntimeException(
						sprintf('Signal property "%s::$%s" is not initialized', $class, $propertyName)
					);
				}

				// Get the signal
				$signal = $property->getValue($object);

				// Use the signal's own name if set, otherwise fall back to the property name
				$key = $signal->getName() ?? $propertyName;
				$this->objectSignals[$object][$key] = $signal;
				
				// Notify any meta-signal listeners that a new signal is available
				$this->signalRegisteredEvent->emit($signal);
			}
			
			return array_values($this->objectSignals[$object]);
		}

		/**
		 * Unregister all signals previously discovered on an object.
		 *
		 * Should be called when the object is done handling its task — for example, at the
		 * end of a request in a dispatcher's finally block. This prevents stale connections
		 * from accumulating across requests.
		 *
		 * Note: because objectSignals uses a WeakMap, signals will eventually be garbage
		 * collected even without calling this method. However, explicit unregistration ensures
		 * meta-signal listeners are notified and connections are cleaned up promptly.
		 *
		 * @param object $object Object whose signals should be removed
		 * @return void
		 */
		public function unregisterSignals(object $object): void {
			if (!isset($this->objectSignals[$object])) {
				return;
			}

			// Notify meta-signal listeners before removal so they can react while the signal
			// is still accessible
			foreach ($this->objectSignals[$object] as $signal) {
				$this->signalUnregisteredEvent->emit($signal);
			}

			unset($this->objectSignals[$object]);
		}

		/**
		 * Manually register a standalone signal with the hub.
		 *
		 * Use this for signals that are not properties of any object — for example, application
		 * lifecycle signals like 'app.booted' or 'app.shutdown'. Unlike object signals, standalone
		 * signals are not garbage collected automatically and must be removed via unregisterSignal().
		 *
		 * @param Signal $signal Signal to register
		 * @return void
		 * @throws \RuntimeException If the signal has no name, or a signal with that name is already registered
		 */
		public function registerSignal(Signal $signal): void {
			$name = $signal->getName();
			
			if ($name === null) {
				throw new \RuntimeException("Cannot register a signal without a name");
			}
			
			if (isset($this->standaloneSignals[$name])) {
				throw new \RuntimeException("Standalone signal '{$name}' is already registered");
			}
			
			$this->signalRegisteredEvent->emit($signal);
			$this->standaloneSignals[$name] = $signal;
		}
		
		/**
		 * Unregister a standalone signal.
		 * @param Signal $signal Signal to unregister
		 * @return bool True if the signal was found and removed, false if it wasn't registered
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
		 * Get a signal by name, optionally scoped to a specific owner.
		 * @param string $name Signal name
		 * @param object|string|null $owner Object reference, class name, or null for standalone signals
		 * @return Signal|null The signal if found, null otherwise
		 */
		public function getSignal(string $name, object|string|null $owner = null): ?Signal {
			if ($owner !== null) {
				if (is_string($owner)) {
					// Class name or interface lookup — O(n) scan, but only one instance per class should be
					// registered at a time (see class docblock). Using instanceof means interface names
					// work transparently alongside concrete class names.
					foreach ($this->objectSignals as $object => $signals) {
						if ($object instanceof $owner) {
							return $signals[$name] ?? null;
						}
					}

					return null;
				}

				// Direct object reference lookup — O(1) via WeakMap
				// WeakMap throws an Error (not a warning) when the key doesn't exist, so we
				// must guard with isset rather than relying on ?? null
				if (!isset($this->objectSignals[$owner])) {
					return null;
				}

				// Return the signal
				return $this->objectSignals[$owner][$name] ?? null;
			}

			// No owner specified — standalone signals only
			return $this->standaloneSignals[$name] ?? null;
		}

		/**
		 * Find signals whose names match a wildcard pattern, optionally filtered by owner.
		 * @param string $pattern Signal name pattern, optionally containing * wildcards
		 * @param object|string|null $owner Object instance, class name, interface name, or null for standalone signals
		 * @return array<string, Signal> Matching signals keyed by name
		 */
		public function findSignals(string $pattern, object|string|null $owner = null): array {
			$results = [];

			// No owner specified — search standalone signals only
			if ($owner === null) {
				foreach ($this->standaloneSignals as $name => $signal) {
					if ($this->matchesPattern($pattern, $name)) {
						$results[$name] = $signal;
					}
				}

				return $results;
			}

			// Object instance — direct WeakMap lookup
			if (is_object($owner)) {
				foreach ($this->objectSignals[$owner] ?? [] as $signalName => $signal) {
					if ($this->matchesPattern($pattern, $signalName)) {
						$results[$signalName] = $signal;
					}
				}

				return $results;
			}

			// Class or interface name — collect signals from all matching objects
			foreach ($this->objectSignals as $object => $signals) {
				if (!($object instanceof $owner)) {
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
		 * Get the meta-signal emitted when any signal is registered or discovered.
		 * Connect to this to monitor hub activity or auto-connect to signals as they appear.
		 * @return Signal
		 */
		public function signalRegistered(): Signal {
			return $this->signalRegisteredEvent;
		}

		/**
		 * Get the meta-signal emitted when any signal is unregistered from the hub.
		 * @return Signal
		 */
		public function signalUnregistered(): Signal {
			return $this->signalUnregisteredEvent;
		}
		
		/**
		 * Use reflection to find all Signal-typed property names on a class.
		 * Results are used to populate the signalPropertyCache.
		 * @param string $class Fully qualified class name
		 * @return list<string> Property names typed as Signal
		 * @throws \ReflectionException
		 */
		private function resolveSignalProperties(string $class): array {
			$properties = [];

			foreach ((new \ReflectionClass($class))->getProperties() as $property) {
				$type = $property->getType();

				if ($type instanceof \ReflectionNamedType && $type->getName() === Signal::class) {
					$properties[] = $property->getName();
				}
			}

			return $properties;
		}

		/**
		 * Test whether a signal name matches a wildcard pattern.
		 * * matches any sequence of characters. Exact matches are handled without regex.
		 * @param string $pattern Pattern to test against
		 * @param string $name Signal name to test
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