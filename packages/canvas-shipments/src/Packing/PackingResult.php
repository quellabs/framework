<?php
	
	namespace Quellabs\Shipments\Packing;
	
	/**
	 * The result of a packing calculation.
	 *
	 * Contains one PackedBox entry per physical box needed, each describing
	 * which items it holds and its gross weight. Feed this into your
	 * ShipmentRequest to create multi-parcel shipments.
	 *
	 * Always call hasUnpackedItems() before proceeding — if true, some items
	 * could not fit any box in the catalog and require manual handling.
	 */
	class PackingResult implements \JsonSerializable {
		
		/** @var PackedBox[] One entry per physical box required */
		private array $packedBoxes;
		
		/** @var PackableItem[] Items that could not be fitted into any available box */
		private array $unpackedItems;
		
		private bool $hasUnpackedItems;
		
		/**
		 * @param PackedBox[] $packedBoxes
		 * @param PackableItem[] $unpackedItems Items that could not be packed (oversize/overweight)
		 */
		public function __construct(array $packedBoxes, array $unpackedItems = []) {
			$this->packedBoxes = $packedBoxes;
			$this->unpackedItems = $unpackedItems;
			$this->hasUnpackedItems = !empty($unpackedItems);
		}
		
		/** @return PackedBox[] */
		public function getPackedBoxes(): array {
			return $this->packedBoxes;
		}
		
		/** Number of physical boxes required. */
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
		
		/**
		 * Items that could not be fitted into any box in the catalog.
		 * Typically oversize or overweight items requiring a manual shipment.
		 * @return PackableItem[]
		 */
		public function getUnpackedItems(): array {
			return $this->unpackedItems;
		}
		
		/**
		 * Serialises the full packing result to a JSON-compatible array.
		 * Automatically called by json_encode().
		 */
		public function jsonSerialize(): array {
			return [
				'boxes'          => $this->packedBoxes,
				'unpacked_items' => $this->unpackedItems,
				'box_count'      => $this->getBoxCount(),
				'total_weight'   => $this->getTotalWeight(),
				'has_unpacked'   => $this->hasUnpackedItems,
			];
		}
	}