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
		 * @var array Connections (callables and their priorities)
		 */
		private array $slots = [];
		
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
		 * Connect a callable to this signal
		 * @param callable $receiver Callable to receive the signal
		 * @param int $priority Connection priority (higher executes first)
		 * @return void
		 * @throws \Exception If types mismatch
		 */
		public function connect(callable $receiver, int $priority = 0): void {
			// Check if this exact connection already exists
			foreach ($this->slots as $connection) {
				if ($connection['receiver'] === $receiver) {
					return; // Connection already exists
				}
			}
			
			// Validate the connection using the type validation system
			ConnectionValidator::validateCallableConnection($receiver, $this->parameterTypes);
			
			// Add connection
			$this->slots[] = [
				'receiver' => $receiver,
				'priority' => $priority
			];
			
			// Sort connections by priority (higher first)
			$this->sortConnectionsByPriority();
		}
		
		/**
		 * Disconnect a specific callable
		 * @param callable $receiver Callable to disconnect
		 * @return bool Whether any connections were removed
		 */
		public function disconnect(callable $receiver): bool {
			$originalCount = count($this->slots);
			
			// Filter out matching connections
			$this->slots = array_filter($this->slots, function ($connection) use ($receiver) {
				return $connection['receiver'] !== $receiver;
			});
			
			// Return true if any connections were removed
			return count($this->slots) < $originalCount;
		}
		
		/**
		 * Emit the signal to all connected receivers
		 * @param mixed ...$args Arguments to pass to slots
		 * @throws \Exception If argument types or count mismatch
		 */
		public function emit(...$args): void {
			// Validate emission arguments using the type validation system
			EmissionValidator::validateEmission($args, $this->parameterTypes);
			
			// Call all connected callables
			foreach ($this->slots as $slot) {
				$slot['receiver'](...$args);
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
			return count($this->slots);
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
		 * Sort connections by priority (higher first)
		 */
		private function sortConnectionsByPriority(): void {
			usort($this->slots, function ($a, $b) {
				return $b['priority'] <=> $a['priority'];
			});
		}
	}