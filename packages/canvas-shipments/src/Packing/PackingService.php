<?php
	
	namespace Quellabs\Shipments\Packing;
	
	use DVDoug\BoxPacker\Packer;
	use DVDoug\BoxPacker\Test\TestItem;
	use DVDoug\BoxPacker\WeightRedistributor;
	
	/**
	 * Calculates the minimum number of boxes needed to ship a set of items,
	 * with configurable weight ceiling per box and best-effort weight balancing
	 * across multiple boxes.
	 *
	 * Requires: dvdoug/boxpacker ^4.0
	 *
	 * Usage:
	 *   $service = new PackingService($boxCatalog, maxWeightPerBox: 20000);
	 *   $service->addItem(new PackableItem('Widget', 100, 80, 60, 250));
	 *   $result = $service->pack();
	 *
	 *   if ($result->hasUnpackedItems()) {
	 *       // handle items that don't fit any box in the catalog
	 *   }
	 *
	 *   foreach ($result->getPackedBoxes() as $packedBox) {
	 *       // feed into ShipmentRequest
	 *   }
	 */
	class PackingService {
		
		/** @var PackingBox[] */
		private array $boxCatalog;
		
		/** @var PackableItem[] */
		private array $items = [];
		
		/**
		 * @param PackingBox[] $boxCatalog     All box sizes available for packing.
		 *                                     Each box's own maxWeight acts as its hard ceiling.
		 *                                     Pass an empty array to use $maxWeightPerBox only.
		 * @param int          $maxWeightPerBox Global weight ceiling in grams applied to every box
		 *                                     in the catalog, overriding any higher per-box limit.
		 *                                     Set to 0 to rely solely on per-box limits.
		 */
		public function __construct(
			array          $boxCatalog,
			private readonly int $maxWeightPerBox = 0,
		) {
			$this->boxCatalog = $this->applyGlobalWeightCeiling($boxCatalog);
		}
		
		/**
		 * Add a single item to the packing list.
		 */
		public function addItem(PackableItem $item): static {
			$this->items[] = $item;
			return $this;
		}
		
		/**
		 * Add multiple items at once.
		 * @param PackableItem[] $items
		 */
		public function addItems(array $items): static {
			foreach ($items as $item) {
				$this->addItem($item);
			}
			return $this;
		}
		
		/**
		 * Clear the current item list (box catalog is retained).
		 */
		public function reset(): static {
			$this->items = [];
			return $this;
		}
		
		/**
		 * Run the bin packing algorithm and return a PackingResult.
		 *
		 * The packer first finds the geometrically optimal assignment, then
		 * WeightRedistributor rebalances weight across boxes of the same type
		 * while respecting each box's maxWeight ceiling.
		 *
		 * @throws \RuntimeException if no box catalog has been configured.
		 */
		public function pack(): PackingResult {
			if (empty($this->boxCatalog)) {
				throw new \RuntimeException("PackingService: no boxes in catalog. Add at least one PackingBox.");
			}
			
			if (empty($this->items)) {
				return new PackingResult([]);
			}
			
			$packer = new Packer();
			
			foreach ($this->boxCatalog as $box) {
				$packer->addBox($box);
			}
			
			foreach ($this->items as $item) {
				$packer->addItem($item);
			}
			
			// First pass: geometrically optimal packing
			$packed = $packer->pack();
			
			// Second pass: redistribute weight across same-size boxes for balance.
			// WeightRedistributor only moves items between boxes of identical dimensions,
			// so geometry is preserved while weight is equalised as much as possible.
			$redistributor = new WeightRedistributor($this->boxCatalog);
			$balanced      = $redistributor->redistributeWeight($packed);
			
			// Map library types back to our own value objects
			$packedBoxes   = [];
			$unpackedItems = [];
			
			foreach ($balanced as $libPackedBox) {
				$box   = $libPackedBox->getBox();
				$items = [];
				
				foreach ($libPackedBox->getItems() as $packedItem) {
					$items[] = $packedItem->getItem();
				}
				
				$packedBoxes[] = new PackedBox(
					box:         $box,
					items:       $items,
					grossWeight: $libPackedBox->getWeight(),
				);
			}
			
			// Collect any items the packer could not fit (oversize or overweight for all boxes)
			foreach ($packer->getUnpackedItems() as $unpackedItem) {
				$unpackedItems[] = $unpackedItem->getItem();
			}
			
			return new PackingResult($packedBoxes, $unpackedItems);
		}
		
		/**
		 * Clamp each box's maxWeight to the global ceiling if one is configured.
		 * We do this by wrapping boxes that exceed the ceiling — PackingBox is
		 * a value object so we reconstruct them with the lower weight.
		 *
		 * @param PackingBox[] $catalog
		 * @return PackingBox[]
		 */
		private function applyGlobalWeightCeiling(array $catalog): array {
			if ($this->maxWeightPerBox <= 0) {
				return $catalog;
			}
			
			return array_map(function (PackingBox $box): PackingBox {
				if ($box->getMaxWeight() <= $this->maxWeightPerBox) {
					return $box;
				}
				
				// Reconstruct with the global ceiling as the effective max weight
				return new PackingBox(
					reference:   $box->getReference(),
					outerWidth:  $box->getOuterWidth(),
					outerLength: $box->getOuterLength(),
					outerDepth:  $box->getOuterDepth(),
					emptyWeight: $box->getEmptyWeight(),
					innerWidth:  $box->getInnerWidth(),
					innerLength: $box->getInnerLength(),
					innerDepth:  $box->getInnerDepth(),
					maxWeight:   $this->maxWeightPerBox,
				);
			}, $catalog);
		}
	}