<?php
	
	namespace Quellabs\Shipments\Packing;
	
	use DVDoug\BoxPacker\Box;
	
	/**
	 * Represents a physical box available in the catalog.
	 *
	 * Outer dimensions describe the physical box size (used for pallet-level
	 * stacking if needed). Inner dimensions are the usable packing space.
	 * In the current setup outer == inner since pallet optimisation is out of
	 * scope, but both are stored so the distinction is available if required.
	 *
	 * Dimensions in millimetres, weights in grams.
	 */
	class PackingBox implements Box, \JsonSerializable {
		
		/**
		 * @param string $reference Unique identifier for this box type (e.g. 'medium-box', 'DHL-2').
		 * @param int $outerWidth External width in mm.
		 * @param int $outerLength External length in mm.
		 * @param int $outerDepth External depth in mm.
		 * @param int $emptyWeight Weight of the box itself in grams (tare weight).
		 * @param int $innerWidth Usable interior width in mm.
		 * @param int $innerLength Usable interior length in mm.
		 * @param int $innerDepth Usable interior depth in mm.
		 * @param int $maxWeight Maximum gross weight this box can hold in grams (contents + tare).
		 */
		public function __construct(
			private readonly string $reference,
			private readonly int    $outerWidth,
			private readonly int    $outerLength,
			private readonly int    $outerDepth,
			private readonly int    $emptyWeight,
			private readonly int    $innerWidth,
			private readonly int    $innerLength,
			private readonly int    $innerDepth,
			private readonly int    $maxWeight,
		) {
		}
		
		/** Unique identifier for this box type. */
		public function getReference(): string {
			return $this->reference;
		}
		
		/** External width in mm. */
		public function getOuterWidth(): int {
			return $this->outerWidth;
		}
		
		/** External length in mm. */
		public function getOuterLength(): int {
			return $this->outerLength;
		}
		
		/** External depth in mm. */
		public function getOuterDepth(): int {
			return $this->outerDepth;
		}
		
		/** Weight of the empty box in grams. */
		public function getEmptyWeight(): int {
			return $this->emptyWeight;
		}
		
		/** Usable interior width in mm. */
		public function getInnerWidth(): int {
			return $this->innerWidth;
		}
		
		/** Usable interior length in mm. */
		public function getInnerLength(): int {
			return $this->innerLength;
		}
		
		/** Usable interior depth in mm. */
		public function getInnerDepth(): int {
			return $this->innerDepth;
		}
		
		/** Maximum gross weight this box can hold in grams (contents + tare). */
		public function getMaxWeight(): int {
			return $this->maxWeight;
		}
		
		/**
		 * Serialises the box dimensions to a JSON-compatible array.
		 * Outer dimensions are omitted as they equal inner dimensions in the
		 * current configuration. Automatically called by json_encode().
		 */
		public function jsonSerialize(): array {
			return [
				'reference'    => $this->reference,
				'inner_width'  => $this->innerWidth,
				'inner_length' => $this->innerLength,
				'inner_depth'  => $this->innerDepth,
				'empty_weight' => $this->emptyWeight,
				'max_weight'   => $this->maxWeight,
			];
		}
	}