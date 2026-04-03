<?php
	
	namespace Quellabs\Shipments\Packing;
	
	use DVDoug\BoxPacker\Item;
	
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
		public function getKeepFlat(): bool      { return $this->keepFlat; }
	}