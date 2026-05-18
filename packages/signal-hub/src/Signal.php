<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Signal class for Qt-inspired event handling in PHP.
	 *
	 * A Signal is an emitter. Objects declare Signal properties and call emit()
	 * when something noteworthy happens. Other code connects Slot instances to the
	 * signal and gets called whenever it fires.
	 *
	 * Ownership:
	 * Signal owns its Slots. Slots are stored in a plain array, giving Signal a
	 * strong reference to each one. This means a Slot stays alive for as long as
	 * it is connected, regardless of whether the caller holds a reference to it.
	 * To remove a connection, call disconnect() explicitly.
	 *
	 * This is intentional. A WeakMap-based approach would require every caller to
	 * hold a strong reference to their Slot for the connection to remain active —
	 * a subtle contract that is easy to violate and fails silently at runtime.
	 * Strong ownership in Signal makes the lifetime of a connection unambiguous:
	 * it exists until disconnect() is called or the Signal itself is destroyed.
	 *
	 * Priority:
	 * Priority is a per-connection property owned by the Signal, not the Slot.
	 * The same Slot can be connected to multiple signals with different priorities.
	 * Slots are sorted by priority on every emit() call.
	 *
	 * @phpstan-type SlotEntry array{slot: Slot, priority: int}
	 */
	class Signal {
		
		/**
		 * Connected slots and their per-connection priority, keyed by spl_object_id.
		 *
		 * Keyed by spl_object_id() of the Slot so that connect(), disconnect(), and
		 * isConnected() are all O(1) lookups without scanning. The Slot object itself
		 * is stored in the entry so it is strongly referenced and cannot be GC'd while
		 * connected.
		 *
		 * Note: spl_object_id() values are reused after an object is destroyed. This is
		 * safe here because a destroyed Slot must have been disconnect()ed first (which
		 * removes the entry), or the Signal itself was holding the only reference (which
		 * means the id cannot be reused while the entry still exists).
		 *
		 * @var array<int, SlotEntry>
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
		 * Signal constructor
		 * @param string|null $name Optional name for this signal
		 * @param object|null $owner Optional owner object
		 */
		public function __construct(?string $name = null, ?object $owner = null) {
			$this->name = $name;
			$this->owner = $owner;
		}
		
		/**
		 * Connect a Slot to this signal.
		 *
		 * If the Slot is already connected, its priority is updated to the new value.
		 * Connecting the same Slot twice does not create duplicate invocations.
		 *
		 * The same Slot instance may be connected to multiple signals simultaneously.
		 * Each signal tracks its own priority for that connection independently.
		 *
		 * @param Slot $slot Slot to connect
		 * @param int $priority Execution order relative to other slots; higher runs first
		 * @return void
		 */
		public function connect(Slot $slot, int $priority = 0): void {
			// Use spl_object_id as the array key for O(1) duplicate detection and disconnect.
			// The Slot object is stored in the entry value to maintain a strong reference.
			$this->slots[spl_object_id($slot)] = [
				'slot'     => $slot,
				'priority' => $priority,
			];
		}
		
		/**
		 * Disconnect a Slot from this signal.
		 *
		 * Safe to call even if the Slot is not connected — returns false in that case.
		 *
		 * @param Slot $slot Slot to disconnect
		 * @return bool True if the Slot was connected and has been removed, false otherwise
		 */
		public function disconnect(Slot $slot): bool {
			$id = spl_object_id($slot);

			if (!isset($this->slots[$id])) {
				return false;
			}
			
			unset($this->slots[$id]);
			return true;
		}
		
		/**
		 * Emit the signal, invoking all connected slots in priority order.
		 *
		 * Slots are sorted by priority on each emit. This keeps connect() cheap
		 * (a single array write) at the cost of a sort on emit. For typical slot
		 * counts this is negligible, and signals are connected far more rarely
		 * than they are emitted.
		 *
		 * Iterates over a snapshot so that a slot may safely call disconnect()
		 * on itself during emission without causing undefined iteration behavior.
		 *
		 * @param mixed ...$args Arguments forwarded to every connected slot
		 * @return void
		 */
		public function emit(mixed ...$args): void {
			// Snapshot before sorting and iterating so mid-emission disconnects
			// do not affect the current emit call
			$slots = $this->slots;
			
			usort($slots, fn($a, $b) => $b['priority'] <=> $a['priority']);
			
			foreach ($slots as $entry) {
				$entry['slot']->invoke(...$args);
			}
		}
		
		/**
		 * Check whether a Slot is currently connected to this signal.
		 * @param Slot $slot Slot to check
		 * @return bool True if the Slot is connected
		 */
		public function isConnected(Slot $slot): bool {
			return isset($this->slots[spl_object_id($slot)]);
		}
		
		/**
		 * Get the number of currently connected slots.
		 * @return int
		 */
		public function countConnections(): int {
			return count($this->slots);
		}
		
		/**
		 * Get the name of this signal.
		 * @return string|null
		 */
		public function getName(): ?string {
			return $this->name;
		}
		
		/**
		 * Get the owner of this signal.
		 * @return object|null
		 */
		public function getOwner(): ?object {
			return $this->owner;
		}
		
		/**
		 * Set the name of this signal.
		 * @param string $name
		 * @return self
		 */
		public function setName(string $name): self {
			$this->name = $name;
			return $this;
		}
		
		/**
		 * Set the owner of this signal.
		 * @param object $owner
		 * @return self
		 */
		public function setOwner(object $owner): self {
			$this->owner = $owner;
			return $this;
		}
	}