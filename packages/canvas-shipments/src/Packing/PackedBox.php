<?php
	
	namespace Quellabs\Shipments\Packing;
	
	/**
	 * Represents a single physical box in a PackingResult.
	 * Wraps the relevant data from dvdoug/boxpacker's packed box
	 * into a clean value object decoupled from the library internals.
	 */
	class PackedBox {
		
		/** @var PackableItem[] */
		private array $items;
		
		public function __construct(
			private readonly PackingBox $box,
			array                       $items,
			private readonly int        $grossWeight,
		) {
			$this->items = $items;
		}
		
		public function getBox(): PackingBox {
			return $this->box;
		}
		
		/** @return PackableItem[] */
		public function getItems(): array {
			return $this->items;
		}
		
		/**
		 * Gross weight = box tare weight + all item weights, in grams.
		 */
		public function getGrossWeight(): int {
			return $this->grossWeight;
		}
		
		/**
		 * Net weight of items only, in grams.
		 */
		public function getItemWeight(): int {
			return array_sum(array_map(fn(PackableItem $i) => $i->getWeight(), $this->items));
		}
	}