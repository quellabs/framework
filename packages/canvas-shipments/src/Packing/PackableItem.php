<?php
	
	namespace Quellabs\Shipments\Packing;
	
	use DVDoug\BoxPacker\Item;
	use DVDoug\BoxPacker\Rotation;
	
	/**
	 * Represents an item to be packed into a shipping box.
	 * Dimensions are in millimetres, weight in grams.
	 *
	 * Pass Rotation::KeepFlat for fragile or "this way up" items that must not
	 * be placed on their side. Pass Rotation::Never for items that cannot be
	 * rotated at all. The default Rotation::BestFit lets the packer choose
	 * whichever orientation yields the most efficient result.
	 */
	readonly class PackableItem implements Item {
		
		/**
		 * @param string $description Human-readable label used in packing results and visualisations.
		 * @param int $width Item width in mm.
		 * @param int $length Item length in mm.
		 * @param int $depth Item depth (height) in mm.
		 * @param int $weight Item weight in grams.
		 * @param Rotation $allowedRotation Rotation constraint. Defaults to BestFit (no restrictions).
		 */
		public function __construct(
			private string   $description,
			private int      $width,
			private int      $length,
			private int      $depth,
			private int      $weight,
			private Rotation $allowedRotation = Rotation::BestFit,
		) {
		}
		
		/** Human-readable label used in packing results and visualisations. */
		public function getDescription(): string {
			return $this->description;
		}
		
		/** Item width in mm. */
		public function getWidth(): int {
			return $this->width;
		}
		
		/** Item length in mm. */
		public function getLength(): int {
			return $this->length;
		}
		
		/** Item depth (height) in mm. */
		public function getDepth(): int {
			return $this->depth;
		}
		
		/** Item weight in grams. */
		public function getWeight(): int {
			return $this->weight;
		}
		
		/**
		 * Rotation constraint passed to the packer.
		 * BestFit (default) — no restrictions, packer chooses optimal orientation.
		 * KeepFlat           — may rotate 90° in the horizontal plane, but never placed on its side.
		 * Never              — must be packed in exactly the orientation specified.
		 */
		public function getAllowedRotation(): Rotation {
			return $this->allowedRotation;
		}
	}