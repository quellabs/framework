<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Central hub for managing signals - focused on registry and discovery
	 */
	class SignalHub {
		
		/**
		 * @var \WeakMap Map of objects to their signal collections
		 * Using WeakMap prevents memory leaks - objects are automatically
		 * removed when they go out of scope elsewhere in the application
		 */
		private \WeakMap $objectSignals;
		
		/**
		 * @var array Map of standalone signals (not owned by objects)
		 * These are signals created directly by the hub, not associated with any object
		 */
		private array $standaloneSignals = [];
		
		/**
		 * SignalHub constructor
		 */
		public function __construct() {
			// Initialize WeakMap for automatic memory management
			$this->objectSignals = new \WeakMap();
		}
		
		/**
		 * Register a signal with the hub
		 * @param Signal $signal Signal object
		 * @return void
		 * @throws \RuntimeException If signal name is null or signal already registered
		 */
		public function registerSignal(Signal $signal): void {
			$name = $signal->getName();
			$owner = $signal->getOwner();
			
			// Signals must have names to be registered in the hub
			if ($name === null) {
				throw new \RuntimeException("Signal name is null");
			}
			
			// Handle standalone signals (no owner object)
			if ($owner === null) {
				// Prevent duplicate standalone signal names
				if (isset($this->standaloneSignals[$name])) {
					throw new \RuntimeException("Standalone signal '{$name}' already registered");
				}
				
				// Register in standalone signals registry
				$this->standaloneSignals[$name] = $signal;
				return;
			}
			
			// Handle object-owned signals
			// Initialize the signal array for this object if it doesn't exist
			if (!isset($this->objectSignals[$owner])) {
				$this->objectSignals[$owner] = [];
			}
			
			// Check for duplicate signal names within the same object
			if (isset($this->objectSignals[$owner][$name])) {
				$ownerClass = get_class($owner);
				throw new \RuntimeException("Signal '{$ownerClass}::{$name}' already registered");
			}
			
			// Register the signal under this object
			$this->objectSignals[$owner][$name] = $signal;
		}
		
		/**
		 * Unregister a signal from the hub
		 * @param Signal $signal Signal object to unregister
		 * @return bool True if the signal was found and removed, false otherwise
		 */
		public function unregisterSignal(Signal $signal): bool {
			$name = $signal->getName();
			$owner = $signal->getOwner();
			
			// Can't unregister signals without names
			if ($name === null) {
				return false;
			}
			
			// Handle standalone signals
			if ($owner === null) {
				// Remove from standalone signals registry
				if (isset($this->standaloneSignals[$name])) {
					unset($this->standaloneSignals[$name]);
					return true;
				}
				
				return false; // Signal not found
			}
			
			// Handle object-owned signals
			// Check if this object has signals and this specific signal exists
			if (isset($this->objectSignals[$owner][$name])) {
				// Remove the specific signal
				unset($this->objectSignals[$owner][$name]);
				
				// Clean up empty signal arrays to keep WeakMap tidy
				// This is optional but helps with memory efficiency
				if (empty($this->objectSignals[$owner])) {
					unset($this->objectSignals[$owner]);
				}
				
				return true;
			}
			
			return false; // Signal not found
		}
		
		/**
		 * Unregister a signal by name and optional owner
		 * @param string $name Signal name
		 * @param object|null $owner Optional owner object (null for standalone signals)
		 * @return bool True if signal was found and removed, false otherwise
		 */
		public function unregisterSignalByName(string $name, ?object $owner = null): bool {
			// Handle standalone signals (no owner specified)
			if ($owner === null) {
				// Check if standalone signal exists
				// Remove from standalone registry
				if (isset($this->standaloneSignals[$name])) {
					unset($this->standaloneSignals[$name]);
					return true;
				}
				
				return false; // Standalone signal not found
			}
			
			// Handle object-owned signals
			// Check if this object has signals and the specific signal exists
			if (isset($this->objectSignals[$owner][$name])) {
				// Remove the signal from this object's signal collection
				unset($this->objectSignals[$owner][$name]);
				
				// Clean up empty signal arrays to maintain WeakMap efficiency
				// When an object has no more signals, remove its entry entirely
				if (empty($this->objectSignals[$owner])) {
					unset($this->objectSignals[$owner]);
				}
				
				return true;
			}
			
			return false; // Object signal not found
		}
		
		/**
		 * Unregister all signals for a specific object
		 * @param object $owner Owner object
		 * @return int Number of signals unregistered
		 */
		public function unregisterObject(object $owner): int {
			// Check if this object has any signals registered
			if (!isset($this->objectSignals[$owner])) {
				return 0; // No signals to unregister
			}
			
			// Count how many signals this object had before removal
			$count = count($this->objectSignals[$owner]);
			
			// Remove all signals for this object
			// WeakMap will handle the memory cleanup automatically
			unset($this->objectSignals[$owner]);
			
			// Return count of removed signals for caller information
			return $count;
		}
		
		/**
		 * Get a signal by name, optionally specifying an owner object
		 * @param string $name Signal name
		 * @param object|null $owner Optional owner object (null for standalone signals)
		 * @return Signal|null The requested signal or null if not found
		 */
		public function getSignal(string $name, ?object $owner = null): ?Signal {
			// Look for standalone signal if no owner specified
			if ($owner === null) {
				// Return standalone signal or null if not found
				return $this->standaloneSignals[$name] ?? null;
			}
			
			// Look for object-owned signal
			// Uses null coalescing to return null if object or signal doesn't exist
			return $this->objectSignals[$owner][$name] ?? null;
		}
		
		/**
		 * Find signals matching a pattern, optionally filtering by owner
		 * @param string $pattern Signal name pattern with optional wildcards
		 * @param object|null $owner Optional owner to filter by
		 * @return array<Signal> Array of matching signals
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
				
				// Get the class name for qualified signal names
				$ownerClass = get_class($object);
				
				// Check each signal belonging to this object
				foreach ($signals as $signalName => $signal) {
					// Create qualified name in format "ClassName::signalName"
					$qualifiedName = $ownerClass . '::' . $signalName;
					
					// Check if the qualified name matches the pattern
					if ($this->matchesPattern($pattern, $qualifiedName)) {
						// Add to results using qualified name as key
						$results[$qualifiedName] = $signal;
					}
				}
			}
			
			return $results;
		}
		
		/**
		 * Get all registered signals
		 * @return array
		 */
		public function getAllSignals(): array {
			$result = [];
			
			// Add standalone signals to the result set
			foreach ($this->standaloneSignals as $name => $signal) {
				// Create a metadata array for each standalone signal
				$result[] = [
					'name'        => $name,                                    // Signal name
					'signal'      => $signal,                               // Actual Signal object
					'paramTypes'  => $signal->getParameterTypes(),     // Expected parameter types
					'connections' => $signal->countConnections(),     // Number of connected slots
					'standalone'  => true                               // Flag indicating this is standalone
				];
			}
			
			// Add object signals to the result set
			foreach ($this->objectSignals as $object => $signals) {
				// Get class name for identification
				$ownerClass = get_class($object);
				
				// Process each signal belonging to this object
				foreach ($signals as $signalName => $signal) {
					// Create a metadata array for each object signal
					$result[] = [
						'owner'       => $object,                                // Reference to owner object
						'class'       => $ownerClass,                           // Owner class name
						'name'        => $signalName,                            // Signal name
						'signal'      => $signal,                              // Actual Signal object
						'paramTypes'  => $signal->getParameterTypes(),    // Expected parameter types
						'connections' => $signal->countConnections(),    // Number of connected slots
						'standalone'  => false                             // Flag indicating this is object-owned
					];
				}
			}
			
			return $result;
		}
		
		/**
		 * Check if a name matches a pattern with wildcards
		 * @param string $pattern Pattern with wildcards
		 * @param string $name Name to check
		 * @return bool
		 */
		private function matchesPattern(string $pattern, string $name): bool {
			// Simple case: if no wildcards, only exact matches count
			if (!str_contains($pattern, '*')) {
				return $pattern === $name;
			}
			
			// Complex case: convert the wildcard pattern to regex
			// First, escape all regex metacharacters to make pattern safe
			$regex = '/^' . preg_quote($pattern, '/') . '$/';
			
			// Then restore wildcards by converting escaped \* back to .*
			// This allows * to match any sequence of characters
			$regex = str_replace('\\*', '.*', $regex);
			
			// Test the name against the regex pattern
			return (bool)preg_match($regex, $name);
		}
	}