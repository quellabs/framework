<?php
	
	namespace Quellabs\Shipments\Packing;
	
	use DVDoug\BoxPacker\Item;
	use DVDoug\BoxPacker\Rotation;
	
	/**
	 * Represents an item to be packed into a shipping box.
	 * Dimensions are in millimetres, weight in grams.
	 */
	class PackableItem implements Item {
		
		public function __construct(
			private readonly string $description,
			private readonly int    $width,
			private readonly int    $length,
			private readonly int    $depth,
			private readonly int    $weight,
			private readonly bool   $keepFlat = false,
		) {}
		
		public function getDescription(): string { return $this->description; }
		public function getWidth(): int          { return $this->width; }
		public function getLength(): int         { return $this->length; }
		public function getDepth(): int          { return $this->depth; }
		public function getWeight(): int         { return $this->weight; }
		
		/**
		 * Controls how the packer is allowed to rotate this item.
		 * Returns KeepFlat for items that must stay upright, BestFit otherwise.
		 * Replaces the v3 getKeepFlat() method.
		 */
		public function getAllowedRotation(): Rotation {
			return $this->keepFlat ? Rotation::KeepFlat : Rotation::BestFit;
		}
	}