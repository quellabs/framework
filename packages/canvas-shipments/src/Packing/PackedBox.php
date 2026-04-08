<?php
	
	namespace Quellabs\Shipments\Packing;
	
	/**
	 * Represents a single physical box in a PackingResult.
	 *
	 * Items carry their placed x/y/z coordinates and post-rotation dimensions,
	 * so this object contains everything needed to both create a shipment parcel
	 * and visualise the box contents.
	 */
	class PackedBox implements \JsonSerializable {
		
		/** @var PackedItem[] Items as placed inside this box, with coordinates and rotated dimensions */
		private array $items;
		
		/**
		 * @param PackingBox $box The box from the catalog that was selected.
		 * @param PackedItem[] $items Items as placed, with x/y/z and post-rotation dimensions.
		 * @param int $grossWeight Total weight of box + contents in grams.
		 */
		public function __construct(
			private readonly PackingBox $box,
			array                       $items,
			private readonly int        $grossWeight,
		) {
			$this->items = $items;
		}
		
		/** The box from the catalog that was selected for this parcel. */
		public function getBox(): PackingBox {
			return $this->box;
		}
		
		/**
		 * Items as placed inside the box, each with x/y/z origin coordinates
		 * and post-rotation dimensions.
		 * @return PackedItem[]
		 */
		public function getItems(): array {
			return $this->items;
		}
		
		/**
		 * Gross weight of box tare + all item weights, in grams.
		 * Use this as the parcel weight when creating a shipment.
		 */
		public function getGrossWeight(): int {
			return $this->grossWeight;
		}
		
		/**
		 * Net weight of items only (excluding box tare), in grams.
		 */
		public function getItemWeight(): int {
			return array_sum(array_map(fn(PackedItem $i) => $i->getItem()->getWeight(), $this->items));
		}
		
		/**
		 * Serialises the box and its placed items to a JSON-compatible array.
		 * Automatically called by json_encode().
		 */
		public function jsonSerialize(): array {
			return [
				'box'          => $this->box,
				'items'        => $this->items,
				'gross_weight' => $this->grossWeight,
				'item_weight'  => $this->getItemWeight(),
			];
		}
	}