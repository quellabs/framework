<?php
	
	namespace Quellabs\Shipments\Packing;
	
	/**
	 * A single item as placed inside a PackedBox.
	 *
	 * Coordinates and dimensions reflect the item's actual orientation after
	 * packing — the packer may rotate items, so $width/$length/$depth here
	 * can differ from the original PackableItem dimensions.
	 *
	 * Origin (0,0,0) is the inner bottom-front-left corner of the box.
	 * Axes: x = width (left→right), y = length (front→back), z = depth (bottom→top).
	 * All values in millimetres.
	 */
	class PackedItem implements \JsonSerializable {
		
		/**
		 * @param PackableItem $item The original item being packed.
		 * @param int $x X position of the item's origin corner within the box, in mm.
		 * @param int $y Y position of the item's origin corner within the box, in mm.
		 * @param int $z Z position (height from box floor) of the item's origin corner, in mm.
		 * @param int $width Placed width — may differ from nominal if the item was rotated.
		 * @param int $length Placed length — may differ from nominal if the item was rotated.
		 * @param int $depth Placed depth — may differ from nominal if the item was rotated.
		 */
		public function __construct(
			private readonly PackableItem $item,
			private readonly int          $x,
			private readonly int          $y,
			private readonly int          $z,
			private readonly int          $width,
			private readonly int          $length,
			private readonly int          $depth,
		) {
		}
		
		/** The original item definition (description, nominal dimensions, weight). */
		public function getItem(): PackableItem {
			return $this->item;
		}
		
		/** X position of the item's origin corner within the box, in mm. */
		public function getX(): int {
			return $this->x;
		}
		
		/** Y position of the item's origin corner within the box, in mm. */
		public function getY(): int {
			return $this->y;
		}
		
		/** Z position (height from box floor) of the item's origin corner, in mm. */
		public function getZ(): int {
			return $this->z;
		}
		
		/** Placed width — may differ from the item's nominal width if rotated. */
		public function getWidth(): int {
			return $this->width;
		}
		
		/** Placed length — may differ from the item's nominal length if rotated. */
		public function getLength(): int {
			return $this->length;
		}
		
		/** Placed depth — may differ from the item's nominal depth if rotated. */
		public function getDepth(): int {
			return $this->depth;
		}
		
		/**
		 * Serialises the placed item to a JSON-compatible array.
		 * Automatically called by json_encode().
		 */
		public function jsonSerialize(): array {
			return [
				'x'           => $this->x,
				'y'           => $this->y,
				'z'           => $this->z,
				'width'       => $this->width,
				'length'      => $this->length,
				'depth'       => $this->depth,
				'description' => $this->item->getDescription(),
				'weight'      => $this->item->getWeight(),
			];
		}
	}