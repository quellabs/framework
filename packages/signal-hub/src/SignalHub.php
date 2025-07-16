<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * This class acts as a centralized registry for all signals in the application,
	 * providing registration, discovery, and lifecycle management capabilities.
	 * It supports both standalone signals and object-owned signals with automatic
	 * memory management through WeakMap usage.
	 */
	class SignalHub {
		
		/**
		 * @var \WeakMap Map of objects to their signal collections
		 * Using WeakMap prevents memory leaks - objects are automatically
		 * removed when they go out of scope elsewhere in the application
		 *
		 * Structure: WeakMap<object, array<string, Signal>>
		 * - Key: The object that owns the signals
		 * - Value: Associative array where keys are signal names and values are Signal objects
		 */
		private \WeakMap $objectSignals;
		
		/**
		 * @var array Map of standalone signals (not owned by objects)
		 * These are signals created directly by the hub, not associated with any object
		 */
		private array $standaloneSignals = [];
		
		/**
		 * Built in events - Meta-signals that notify about hub state changes
		 * These signals are emitted when other signals are registered/unregistered
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
			// Initialize WeakMap for automatic memory management
			// WeakMap automatically removes entries when the key object is garbage collected
			$this->objectSignals = new \WeakMap();
			
			// Create meta-signals for hub events
			// These allow other components to listen for registration/unregistration events
			$this->signalRegisteredEvent = new Signal(['object'], 'hub.signal.registered');
			$this->signalUnregisteredEvent = new Signal(['object'], 'hub.signal.unregistered');
		}
		
		/**
		 * Register a signal with the hub
		 * @param Signal $signal Signal object to register
		 * @return void
		 * @throws \RuntimeException If signal name is null or signal already registered
		 */
		public function registerSignal(Signal $signal): void {
			// Get signal metadata for registration logic
			$name = $signal->getName();
			$owner = $signal->getOwner();
			
			// Signals must have names to be registered in the hub
			// Anonymous signals cannot be discovered or referenced
			if ($name === null) {
				throw new \RuntimeException("Signal name is null");
			}
			
			// Handle standalone signals (no owner object)
			if ($owner === null) {
				// Prevent duplicate standalone signal names
				// Each standalone signal must have a unique name across the entire hub
				if (isset($this->standaloneSignals[$name])) {
					throw new \RuntimeException("Standalone signal '{$name}' already registered");
				}
				
				// Emit registration event before actual registration
				// This allows listeners to react to new signals being added
				$this->signalRegisteredEvent->emit($signal);
				
				// Register in standalone signals registry
				$this->standaloneSignals[$name] = $signal;
				return;
			}
			
			// Handle object-owned signals
			// Initialize the signal array for this object if it doesn't exist
			// WeakMap entries are created on-demand
			if (!isset($this->objectSignals[$owner])) {
				$this->objectSignals[$owner] = [];
			}
			
			// Check for duplicate signal names within the same object
			// Each object can have multiple signals, but names must be unique per object
			if (isset($this->objectSignals[$owner][$name])) {
				$ownerClass = get_class($owner);
				throw new \RuntimeException("Signal '{$ownerClass}::{$name}' already registered");
			}
			
			// Emit registration event before actual registration
			$this->signalRegisteredEvent->emit($signal);
			
			// Register the signal under this object
			// Object can have multiple signals, each with unique names
			$this->objectSignals[$owner][$name] = $signal;
		}
		
		/**
		 * Unregister a signal from the hub
		 * @param Signal $signal Signal object to unregister
		 * @return bool True if the signal was found and removed, false otherwise
		 */
		public function unregisterSignal(Signal $signal): bool {
			// Get signal metadata for unregistration logic
			$name = $signal->getName();
			$owner = $signal->getOwner();
			
			// Can't unregister signals without names
			// Anonymous signals are not tracked in the registry
			if ($name === null) {
				return false;
			}
			
			// Handle standalone signals
			if ($owner === null) {
				// Remove from standalone signals registry
				if (isset($this->standaloneSignals[$name])) {
					// Emit unregistration event before removal
					$this->signalUnregisteredEvent->emit($this->standaloneSignals[$name]);
					
					// Remove the signal from registry
					unset($this->standaloneSignals[$name]);
					return true;
				}
				
				return false; // Signal not found in standalone registry
			}
			
			// Handle object-owned signals
			// Check if this object has signals and this specific signal exists
			if (isset($this->objectSignals[$owner][$name])) {
				// Send meta event before removal
				// Note: This should emit the actual signal being removed, not from standaloneSignals
				$this->signalUnregisteredEvent->emit($this->objectSignals[$owner][$name]);
				
				// Remove the specific signal from the object's signal collection
				unset($this->objectSignals[$owner][$name]);
				
				// Clean up empty signal arrays to keep WeakMap tidy
				// This is optional but helps with memory efficiency
				// If object has no more signals, remove it entirely from WeakMap
				if (empty($this->objectSignals[$owner])) {
					unset($this->objectSignals[$owner]);
				}
				
				return true;
			}
			
			return false; // Signal not found in object signals
		}
		
		/**
		 * Find signal by name and optional owner.
		 * @param string $name Signal name to search for
		 * @param object|null $owner Optional owner object to limit search scope
		 * @return Signal|null The found signal or null if not found
		 */
		public function getSignal(string $name, ?object $owner = null): ?Signal {
			// Look for object-owned signal when owner is specified
			// This provides direct access to signals owned by specific objects
			if ($owner !== null) {
				return $this->objectSignals[$owner][$name] ?? null;
			}
			
			// When no owner specified, search through all object signals first
			// This prioritizes object-owned signals over standalone signals
			foreach ($this->objectSignals as $signals) {
				if (isset($signals[$name])) {
					return $signals[$name];
				}
			}
			
			// Fall back to standalone signals if not found in any object
			// Standalone signals are checked last in the search hierarchy
			return $this->standaloneSignals[$name] ?? null;
		}
		
		/**
		 * Find signals matching a pattern, optionally filtering by owner
		 * @param string $pattern Signal name pattern with optional wildcards (*)
		 * @param object|null $owner Optional owner to filter by
		 * @return array<Signal> Array of matching signals keyed by signal name
		 */
		public function findSignals(string $pattern, ?object $owner = null): array {
			$results = [];
			
			// Search standalone signals if no specific owner requested
			if ($owner === null) {
				// Iterate through all standalone signals
				foreach ($this->standaloneSignals as $name => $signal) {
					// Check if signal name matches the pattern
					if ($this->matchesPattern($pattern, $name)) {
						// Add matching signal to results using its name as key
						// This prevents duplicates and provides easy access by name
						$results[$name] = $signal;
					}
				}
			}
			
			// Search object signals
			// WeakMap iteration works like a regular array
			foreach ($this->objectSignals as $object => $signals) {
				// Skip objects that don't match the requested owner filter
				if ($owner !== null && $object !== $owner) {
					continue;
				}
				
				// Check each signal belonging to this object
				foreach ($signals as $signalName => $signal) {
					// Test signal name against the pattern
					if ($this->matchesPattern($pattern, $signalName)) {
						// Add to results, potentially overwriting standalone signals
						// This gives object signals precedence over standalone signals
						$results[$signalName] = $signal;
					}
				}
			}
			
			return $results;
		}
		
		/**
		 * Get the signal that is emitted when a new signal is registered with the hub
		 * @return Signal The signal that emits when signals are registered (parameter: Signal object)
		 */
		public function signalRegistered(): Signal {
			return $this->signalRegisteredEvent;
		}
		
		/**
		 * Get the signal that is emitted when a signal is unregistered from the hub
		 * @return Signal The signal that emits when signals are unregistered (parameter: Signal object)
		 */
		public function signalUnregistered(): Signal {
			return $this->signalUnregisteredEvent;
		}
		
		/**
		 * Check if a name matches a pattern with wildcards
		 * @param string $pattern Pattern with wildcards (* matches any sequence)
		 * @param string $name Name to check against the pattern
		 * @return bool True if name matches pattern, false otherwise
		 */
		private function matchesPattern(string $pattern, string $name): bool {
			// Simple case: if no wildcards, only exact matches count
			// This optimization avoids regex overhead for simple exact matches
			if (!str_contains($pattern, '*')) {
				return $pattern === $name;
			}
			
			// Complex case: convert the wildcard pattern to regex
			// First, escape all regex metacharacters to make pattern safe
			// This prevents pattern characters from being interpreted as regex syntax
			$regex = '/^' . preg_quote($pattern, '/') . '$/';
			
			// Then restore wildcards by converting escaped \* back to .*
			// This allows * to match any sequence of characters
			// preg_quote() escapes * to \*, so we convert it back to .* for regex
			$regex = str_replace('\\*', '.*', $regex);
			
			// Test the name against the regex pattern
			// ^ and $ anchors ensure the entire string must match
			return (bool)preg_match($regex, $name);
		}
	}