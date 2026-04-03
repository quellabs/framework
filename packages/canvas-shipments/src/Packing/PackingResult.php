<?php
	
	namespace Quellabs\Shipments\Packing;
	
	/**
	 * The result of a packing calculation.
	 *
	 * Contains one PackedBox entry per physical box needed, each describing
	 * which items it holds and its gross weight. Feed this into your
	 * ShipmentRequest to create multi-parcel shipments.
	 */
	class PackingResult {
		
		/** @var PackedBox[] */
		private array $packedBoxes;
		
		private bool $hasUnpackedItems;
		
		/** @var PackableItem[] */
		private array $unpackedItems;
		
		/**
		 * @param PackedBox[]    $packedBoxes
		 * @param PackableItem[] $unpackedItems Items that could not be packed (oversize/overweight)
		 */
		public function __construct(array $packedBoxes, array $unpackedItems = []) {
			$this->packedBoxes      = $packedBoxes;
			$this->unpackedItems    = $unpackedItems;
			$this->hasUnpackedItems = !empty($unpackedItems);
		}
		
		/** @return PackedBox[] */
		public function getPackedBoxes(): array {
			return $this->packedBoxes;
		}
		
		public function getBoxCount(): int {
			return count($this->packedBoxes);
		}
		
		/**
		 * Total gross weight across all boxes (box tare + items), in grams.
		 */
		public function getTotalWeight(): int {
			return array_sum(array_map(fn(PackedBox $b) => $b->getGrossWeight(), $this->packedBoxes));
		}
		
		/**
		 * Whether any items could not be fitted into the available box catalog.
		 * Always check this before passing the result to ShipmentRequest.
		 */
		public function hasUnpackedItems(): bool {
			return $this->hasUnpackedItems;
		}
		
		/** @return PackableItem[] */
		public function getUnpackedItems(): array {
			return $this->unpackedItems;
		}
	}