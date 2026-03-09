<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Signal class for Qt-inspired event handling in PHP
	 */
	class Signal {
		
		/**
		 * @var array Connections (callables and their priorities)
		 */
		private array $slots = [];
		
		/**
		 * @var string|null Name of this signal
		 */
		private ?string $name;
		
		/**
		 * @var object|null Object that owns this signal
		 */
		private ?object $owner;
		
		/**
		 * @param string|null $name Optional name for this signal
		 * @param object|null $owner Optional owner object
		 */
		public function __construct(?string $name = null, ?object $owner = null) {
			$this->name = $name;
			$this->owner = $owner;
		}
		
		/**
		 * Connect a callable to this signal
		 * @param callable $receiver Callable to receive the signal
		 * @param int $priority Higher priority executes first
		 * @return void
		 */
		public function connect(callable $receiver, int $priority = 0): void {
			// Skip if already connected
			foreach ($this->slots as $slot) {
				if ($slot['receiver'] === $receiver) {
					return;
				}
			}
			
			$this->slots[] = [
				'receiver' => $receiver,
				'priority' => $priority
			];
			
			$this->sortConnectionsByPriority();
		}
		
		/**
		 * Disconnect a specific callable
		 * @param callable $receiver Callable to disconnect
		 * @return bool True if any connections were removed
		 */
		public function disconnect(callable $receiver): bool {
			$originalCount = count($this->slots);
			
			$this->slots = array_values(array_filter(
				$this->slots,
				fn($slot) => $slot['receiver'] !== $receiver
			));
			
			return count($this->slots) < $originalCount;
		}
		
		/**
		 * Emit the signal to all connected receivers
		 * @param mixed ...$args Arguments to pass to slots
		 */
		public function emit(mixed ...$args): void {
			foreach ($this->slots as $slot) {
				$slot['receiver'](...$args);
			}
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
			usort($this->slots, fn($a, $b) => $b['priority'] <=> $a['priority']);
		}
	}