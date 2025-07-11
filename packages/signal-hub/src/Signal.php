<?php
	
	namespace Quellabs\SignalHub;
	
	use Quellabs\SignalHub\Validation\EmissionValidator;
	use Quellabs\SignalHub\Validation\ConnectionValidator;
	
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
		private ?string $name;
		
		/**
		 * @var object|null Object that owns this signal
		 */
		private ?object $owner;
		
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
			// Validate emission arguments using the type validation system
			EmissionValidator::validateEmission($args, $this->parameterTypes);
			
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
			return (bool)preg_match($regex, $this->name);
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
				'pattern'  => $pattern,
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
			// Check if this connection already exists
			foreach ($this->connections as $connection) {
				if ($connection['receiver'] === $receiver && $connection['slot'] === $slot) {
					return false; // Connection already exists
				}
			}
			
			// Validate the connection using the type validation system
			ConnectionValidator::validateObjectMethodConnection($receiver, $slot, $this->parameterTypes);
			
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
			
			// Validate the connection using the type validation system
			ConnectionValidator::validateCallableConnection($receiver, $this->parameterTypes);
			
			// Add the new connection to the connections array
			// 'slot' is null because this is a direct callable, not an object method
			$this->connections[] = [
				'receiver' => $receiver,  // The callable function
				'slot'     => null,       // No slot name for direct callables
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
			usort($this->connections, function ($a, $b) {
				return $b['priority'] <=> $a['priority'];
			});
		}
	}