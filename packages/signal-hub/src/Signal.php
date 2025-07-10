<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Signal class for Qt-like event handling in PHP
	 */
	class Signal {
		/**
		 * @var array Expected parameter types for this signal
		 */
		private array $parameterTypes;
		
		/**
		 * @var array Direct connections (receivers and their slots)
		 */
		private array $connections = [];
		
		/**
		 * @var array Pattern connections (patterns and their handlers)
		 */
		private array $patternConnections = [];
		
		/**
		 * @var string|null Name of this signal (for debugging)
		 */
		private ?string $name = null;
		
		/**
		 * @var object|null Object that owns this signal
		 */
		private ?object $owner = null;
		
		/**
		 * Constructor to initialize the signal with parameter types
		 * @param array $parameterTypes Expected parameter types for this signal
		 * @param string|null $name Optional name for this signal
		 * @param object|null $owner Optional owner object
		 */
		public function __construct(array $parameterTypes, ?string $name = null, ?object $owner = null) {
			$this->parameterTypes = $parameterTypes;
			$this->name = $name;
			$this->owner = $owner;
		}
		
		/**
		 * Disconnect a specific receiver or slot
		 * @param callable|object $receiver Object or callable to disconnect
		 * @param string|null $slot Method name (if receiver is an object)
		 * @return bool Whether any connections were removed
		 */
		public function disconnect(callable|object $receiver, ?string $slot = null): bool {
			$originalCount = count($this->connections);
			
			// Filter out matching connections
			$this->connections = array_filter($this->connections, function ($connection) use ($receiver, $slot) {
				// If the receiver doesn't match, keep the connection
				if ($connection['receiver'] !== $receiver) {
					return true;
				}
				
				// If a slot is specified, only disconnect that slot
				if ($slot !== null && $connection['slot'] !== $slot) {
					return true;
				}
				
				// Disconnect
				return false;
			});
			
			// Return true if any connections were removed
			return count($this->connections) < $originalCount;
		}
		
		/**
		 * Emit the signal to all connected receivers
		 * @param mixed ...$args Arguments to pass to slots
		 * @throws \Exception If argument types or count mismatch
		 */
		public function emit(...$args): void {
			// Check argument count
			if (count($args) !== count($this->parameterTypes)) {
				throw new \Exception("Argument count mismatch for signal emission.");
			}
			
			// Check argument types
			foreach ($args as $index => $arg) {
				$expectedType = $this->parameterTypes[$index];
				$actualType = is_object($arg) ? get_class($arg) : gettype($arg);
				
				if (!$this->isTypeCompatible($actualType, $expectedType)) {
					throw new \Exception("Type mismatch for argument {$index} of signal emission: expected {$expectedType}, got {$actualType}.");
				}
			}
			
			// Call the direct connections
			foreach ($this->connections as $connection) {
				$receiver = $connection['receiver'];
				$slot = $connection['slot'];
				
				if ($slot === null) {
					$receiver(...$args);
				} else {
					$receiver->$slot(...$args);
				}
			}
			
			// Process pattern connections if this signal has a name
			if ($this->name !== null) {
				foreach ($this->patternConnections as $patternConnection) {
					$pattern = $patternConnection['pattern'];
					
					// If this signal's name matches the pattern
					if ($this->matchesPattern($pattern)) {
						$receiver = $patternConnection['receiver'];
						
						// Call the pattern receiver
						if (is_callable($receiver)) {
							$receiver(...$args);
						} elseif (is_object($receiver) && method_exists($receiver, 'handle')) {
							// Default handler method if none specified
							$receiver->handle(...$args);
						}
					}
				}
			}
		}
		
		/**
		 * Get parameter types for this signal
		 * @return array
		 */
		public function getParameterTypes(): array {
			return $this->parameterTypes;
		}
		
		/**
		 * Get number of connections
		 * @return int
		 */
		public function countConnections(): int {
			return count($this->connections) + count($this->patternConnections);
		}
		
		/**
		 * Get the name of this signal
		 * @return string|null
		 */
		public function getName(): ?string {
			return $this->name;
		}
		
		/**
		 * Get the owner of this signal
		 * @return object|null
		 */
		public function getOwner(): ?object {
			return $this->owner;
		}
		
		/**
		 * Set the name of this signal
		 * @param string $name
		 * @return self
		 */
		public function setName(string $name): self {
			$this->name = $name;
			return $this;
		}
		
		/**
		 * Set the owner of this signal
		 * @param object $owner
		 * @return self
		 */
		public function setOwner(object $owner): self {
			$this->owner = $owner;
			return $this;
		}

		/**
		 * Normalizes type strings to consistent notation
		 * @param string $type Raw type string
		 * @return string Normalized type string
		 */
		private function normalizeType(string $type): string {
			$typeMap = [
				'integer' => 'int',
				'boolean' => 'bool',
				'double'  => 'float',
			];
			
			return $typeMap[$type] ?? $type;
		}
		
		/**
		 * Checks if a type from a signal parameter is compatible with a slot parameter type
		 * @param string $signalType The type of the value being passed (from signal emission)
		 * @param string $slotType The type declaration of the receiving parameter
		 * @return bool True if types are compatible, false otherwise
		 */
		private function isTypeCompatible(string $signalType, string $slotType): bool {
			// Normalize type names to handle aliases
			$signalType = $this->normalizeType($signalType);
			$slotType = $this->normalizeType($slotType);
			
			// Handle the special case for generic 'object' type
			if ($this->isGenericObjectCompatibility($signalType, $slotType)) {
				return true;
			}
			
			// If types are exactly the same, they're compatible
			if ($signalType === $slotType) {
				return true;
			}
			
			// Handle primitive types compatibility
			if ($this->involvesNonCompatiblePrimitives($signalType, $slotType)) {
				return false;
			}
			
			// Check class inheritance for compatibility
			return $this->hasInheritanceRelationship($signalType, $slotType);
		}
		
		/**
		 * Checks if either type is a generic 'object' type and the other is a class
		 * @param string $typeA First type to check
		 * @param string $typeB Second type to check
		 * @return bool True if one is 'object' and the other is a class
		 */
		private function isGenericObjectCompatibility(string $typeA, string $typeB): bool {
			// If typeB is generic 'object', check if typeA is a class
			if ($typeB === 'object' && $this->isClassName($typeA)) {
				return true;
			}
			
			// If typeA is generic 'object', check if typeB is a class
			if ($typeA === 'object' && $this->isClassName($typeB)) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Determines if a type string represents a class name
		 * @param string $type Type to check
		 * @return bool True if the type is likely a class name
		 */
		private function isClassName(string $type): bool {
			return str_starts_with($type, '\\') || class_exists($type);
		}
		
		/**
		 * Checks if the types involve non-compatible primitive types
		 * @param string $typeA First type to check
		 * @param string $typeB Second type to check
		 * @return bool True if types are primitive and not compatible
		 */
		private function involvesNonCompatiblePrimitives(string $typeA, string $typeB): bool {
			$primitiveTypes = ['int', 'float', 'string', 'bool', 'array'];
			
			$isTypeAPrimitive = in_array($typeA, $primitiveTypes);
			$isTypeBPrimitive = in_array($typeB, $primitiveTypes);
			
			// If one is primitive and the other isn't, they're not compatible
			if ($isTypeAPrimitive !== $isTypeBPrimitive) {
				return true;
			}
			
			// If both are primitives but different types, they're not compatible
			if ($isTypeAPrimitive && $isTypeBPrimitive && $typeA !== $typeB) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Checks if two class types have an inheritance relationship
		 * @param string $classA First class name
		 * @param string $classB Second class name
		 * @return bool True if one class inherits from the other
		 */
		private function hasInheritanceRelationship(string $classA, string $classB): bool {
			// Check if either class inherits from the other
			return is_subclass_of($classA, $classB) || is_subclass_of($classB, $classA);
		}
		
		/**
		 * Check if a pattern matches this signal's name
		 * @param string $pattern Pattern with wildcards
		 * @return bool True if matches
		 */
		private function matchesPattern(string $pattern): bool {
			if ($this->name === null) {
				return false;
			}
			
			// If there's no wildcard, it's only a match if exact
			if (!str_contains($pattern, '*')) {
				return $pattern === $this->name;
			}
			
			// Convert the pattern to a regex
			// Escape dots in the pattern and replace * with .*
			$regex = '/^' . str_replace(['.', '*'], ['\.', '.*'], $pattern) . '$/';
			
			// Check if the signal name matches the pattern
			return (bool) preg_match($regex, $this->name);
		}
		
		/**
		 * Connect an object's method or a callable to this signal
		 * This method now supports both direct connections and pattern-based connections
		 * @param callable|object $receiver Object or callable to receive the signal
		 * @param string|null $slotOrPattern Method name (if receiver is an object) or pattern string
		 * @param int $priority Connection priority (higher executes first)
		 * @return bool Whether connection was successful
		 * @throws \Exception If types mismatch or slot doesn't exist
		 */
		public function connect(callable|object $receiver, ?string $slotOrPattern = null, int $priority = 0): bool {
			// If the receiver is a closure/callable and the second param is provided,
			// it should always be treated as a pattern, not a slot method name
			if (is_callable($receiver) && !is_object($receiver) && $slotOrPattern !== null) {
				return $this->connectPattern($slotOrPattern, $receiver, $priority);
			}
			
			// If the receiver is a callable object (not implementing __invoke)
			// and the second param is a string, it's a slot method name
			if (is_object($receiver) && !is_callable($receiver) && $slotOrPattern !== null) {
				return $this->connectObject($receiver, $slotOrPattern, $priority);
			}
			
			// If the receiver is a callable object (implementing __invoke)
			// and the second param is provided, treat it as a pattern
			if (is_object($receiver) && is_callable($receiver) && $slotOrPattern !== null) {
				return $this->connectPattern($slotOrPattern, $receiver, $priority);
			}
			
			// For callable with no second param, connect directly
			if (is_callable($receiver) && $slotOrPattern === null) {
				return $this->connectCallable($receiver, $priority);
			}
			
			// If we reach here, something is wrong
			throw new \Exception("Invalid connection parameters.");
		}
		
		/**
		 * Connect using a pattern
		 * @param string $pattern Pattern to match
		 * @param callable|object $receiver Receiver
		 * @param int $priority Priority
		 * @return bool Always true (success)
		 */
		private function connectPattern(string $pattern, callable|object $receiver, int $priority = 0): bool {
			// Add to pattern connections
			$this->patternConnections[] = [
				'pattern' => $pattern,
				'receiver' => $receiver,
				'priority' => $priority
			];
			
			return true;
		}
		
		/**
		 * Connect an object and its slot method to this signal
		 * @param object $receiver Object that will receive the signal
		 * @param string $slot Method name to be called
		 * @param int $priority Connection priority
		 * @return bool Whether connection was successful
		 * @throws \Exception If types mismatch or slot doesn't exist
		 */
		private function connectObject(object $receiver, string $slot, int $priority = 0): bool {
			// Check if slot method exists on receiver
			if (!method_exists($receiver, $slot)) {
				throw new \Exception("Slot '{$slot}' does not exist on receiver.");
			}
			
			// Check if this connection already exists
			foreach ($this->connections as $connection) {
				if ($connection['receiver'] === $receiver && $connection['slot'] === $slot) {
					return false; // Connection already exists
				}
			}
			
			// Get reflection of slot method
			$slotReflection = new \ReflectionMethod($receiver, $slot);
			$slotParams = $slotReflection->getParameters();
			
			// Check parameter count
			if (count($this->parameterTypes) !== count($slotParams)) {
				throw new \Exception("Signal and slot parameter count mismatch.");
			}
			
			// Check type compatibility for each parameter
			for ($i = 0; $i < count($this->parameterTypes); $i++) {
				$signalType = $this->parameterTypes[$i];
				$slotType = $slotParams[$i]->getType();
				
				if ($slotType === null) {
					throw new \Exception("Slot parameter {$i} is not typed.");
				}
				
				// Fetch the name of the slot by casting to string.
				// This will call the __toString magic method.
				// We can't use ->getName() because it may not be present
				$slotTypeName = (string)$slotType;
				
				/**
				 * Check type compatibility
				 */
				if (!$this->isTypeCompatible($signalType, $slotTypeName)) {
					throw new \Exception("Type mismatch for parameter {$i} between signal ({$signalType}) and slot ({$slotTypeName}).");
				}
			}
			
			// Add connection
			$this->connections[] = [
				'receiver' => $receiver,
				'slot'     => $slot,
				'priority' => $priority
			];
			
			// Sort connections by priority (higher first)
			$this->sortConnectionsByPriority();
			
			return true;
		}
		
		/**
		 * Connect a callable to this signal
		 *
		 * This method establishes a connection between the signal and a callable function.
		 * It performs type checking to ensure signal and receiver parameters are compatible,
		 * prevents duplicate connections, and organizes connections by priority.
		 * @param callable $receiver Callable function to receive the signal
		 * @param int $priority Connection priority - higher priority connections are executed first
		 * @return bool Whether connection was successful (false if connection already exists)
		 * @throws \Exception If types mismatch between signal parameters and receiver parameters
		 */
		private function connectCallable(callable $receiver, int $priority = 0): bool {
			// Check if this exact connection already exists to prevent duplicates
			foreach ($this->connections as $connection) {
				if ($connection['receiver'] === $receiver && $connection['slot'] === null) {
					return false; // Connection already exists, avoid duplicate registrations
				}
			}
			
			// Handle array-style callable in format [$object, 'methodName']
			// Redirects to connectObject method which is specialized for object method connections
			if (is_array($receiver) && count($receiver) === 2 && is_object($receiver[0]) && is_string($receiver[1])) {
				return $this->connectObject($receiver[0], $receiver[1], $priority);
			}
			
			// Create a reflection of the callable to inspect its parameters
			$slotReflection = new \ReflectionFunction($receiver);
			$slotParams = $slotReflection->getParameters();
			
			// Verify that the signal and receiver have the same number of parameters
			if (count($this->parameterTypes) !== count($slotParams)) {
				throw new \Exception("Signal and slot parameter count mismatch.");
			}
			
			// Iterate through each parameter to verify type compatibility
			for ($i = 0; $i < count($this->parameterTypes); $i++) {
				$signalType = $this->parameterTypes[$i];
				$slotType = $slotParams[$i]->getType();
				
				// Ensure all slot parameters have type declarations
				if ($slotType === null) {
					throw new \Exception("Slot parameter {$i} is not typed.");
				}
				
				// Fetch the name of the slot by casting to string.
				// This will call the __toString magic method.
				// We can't use ->getName() because it may not be present
				$slotTypeName = (string)$slotType;
				
				// Check if the signal type is compatible with the slot type
				if (!$this->isTypeCompatible($signalType, $slotTypeName)) {
					throw new \Exception("Type mismatch for parameter {$i} between signal ({$signalType}) and slot ({$slotTypeName}).");
				}
			}
			
			// Add the new connection to the connections array
			// 'slot' is null because this is a direct callable, not an object method
			$this->connections[] = [
				'receiver' => $receiver,  // The callable function
				'slot' => null,           // No slot name for direct callables
				'priority' => $priority   // Priority determines execution order
			];
			
			// Re-sort all connections based on priority value
			// Higher priority connections will be executed first when the signal is emitted
			$this->sortConnectionsByPriority();
			
			// Connection successfully established
			return true;
		}
		
		/**
		 * Sort connections by priority (higher first)
		 */
		private function sortConnectionsByPriority(): void {
			usort($this->connections, function($a, $b) {
				return $b['priority'] <=> $a['priority'];
			});
		}
		
	}
