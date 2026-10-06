<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	
	use Quellabs\Recommender\Reconciliation\ReconciledRecommendation;
	
	/** Immutable ordered candidates or the application's selected display. */
	readonly class RecommendationList {
		
		/** @var int Resolved category */
		public int $category;
		
		/** @var string Display surface */
		public string $placement;
		
		/** @var array<int, RecommendationSource> Enabled source set */
		public array $sources;
		
		/** @var string|null Model and logging partition */
		public ?string $contextKey;
		
		/** @var ScoreKind How the item scores are interpreted */
		public ScoreKind $scoreKind;
		
		/** @var string|null ID of the scorer that ranked the items, null for rank fusion */
		public ?string $scorerId;
		
		/** @var array<int, ReconciledRecommendation> Ordered results */
		public array $items;
		
		/** @var int Maximum selectable displayed items */
		public int $limit;
		
		/**
		 * Build a list, rejecting metadata or items that do not fit together.
		 * @param int $category Resolved category
		 * @param string $placement Display surface
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param string|null $contextKey Model and logging partition
		 * @param ScoreKind $scoreKind How the item scores are interpreted
		 * @param string|null $scorerId Opaque ID of the scorer that ranked the items
		 * @param array<int, ReconciledRecommendation> $items Ordered results
		 * @param int $limit Maximum selectable displayed items, from 1 to 100
		 * @throws \InvalidArgumentException When the metadata or items are invalid
		 */
		private function __construct(
			int     $category,
			string  $placement,
			array   $sources,
			?string $contextKey,
			ScoreKind $scoreKind,
			?string $scorerId,
			array   $items,
			int     $limit
		) {
			if ($category < 0 || $category > Identifier::MAX) {
				throw new \InvalidArgumentException("Category must be an unsigned 32-bit integer, got {$category}.");
			}
			
			if ($limit < 1 || $limit > 100) {
				throw new \InvalidArgumentException("Limit must be between 1 and 100, got {$limit}.");
			}
			
			if ($scorerId !== null && $scoreKind !== ScoreKind::Ranked) {
				throw new \InvalidArgumentException("A scorer ID requires a ranked list; got score kind '{$scoreKind->value}'.");
			}
			
			Identifier::validateKey($placement, 64, 'placement');
			
			if ($contextKey !== null) {
				Identifier::validateKey($contextKey, 128, 'context');
			}
			
			
			$sourceSet = self::sourceSet($sources);
			self::validateItems($items, $scoreKind, $sourceSet);
			
			$this->category = $category;
			$this->placement = $placement;
			$this->sources = $sources;
			$this->contextKey = $contextKey;
			$this->scoreKind = $scoreKind;
			$this->scorerId = $scorerId;
			$this->items = $items;
			$this->limit = $limit;
		}
		
		/**
		 * Build a direct display list from items in the order they were shown.
		 * @param int $category Resolved category
		 * @param string $placement Display surface
		 * @param array<int, ReconciledRecommendation> $items Displayed items in order
		 * @param string|null $contextKey Optional model partition
		 * @return self Direct display list
		 * @throws \InvalidArgumentException When an item is not a ReconciledRecommendation
		 */
		public static function fromDisplayedItems(int $category, string $placement, array $items, ?string $contextKey = null): self {
			$sources = [];
			
			foreach ($items as $item) {
				if (!$item instanceof ReconciledRecommendation) {
					throw new \InvalidArgumentException('Displayed items must be ReconciledRecommendation instances, got ' . get_debug_type($item) . '.');
				}
				
				foreach ($item->evidence as $signal) {
					$sources[$signal->source->value] = $signal->source;
				}
			}
			
			return new self($category, $placement, array_values($sources), $contextKey, ScoreKind::Direct, null,
				$items, max(1, count($items)));
		}
		
		/**
		 * Build a ranked list with the given scorer and item order.
		 * @param int $category Resolved category
		 * @param string $placement Display surface
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param string|null $contextKey Model and logging partition
		 * @param string|null $scorerId Opaque ID of the scorer, null for rank fusion
		 * @param array<int, ReconciledRecommendation> $items Ordered results
		 * @param int $limit Maximum selectable displayed items, from 1 to 100
		 * @return self Ranked list
		 * @throws \InvalidArgumentException When the metadata or items are invalid
		 */
		public static function ranked(int $category, string $placement, array $sources, ?string $contextKey,
			?string $scorerId, array $items, int $limit): self {
			return new self($category, $placement, $sources, $contextKey, ScoreKind::Ranked, $scorerId, $items, $limit);
		}
		
		/**
		 * Return a list containing only the selected products, in the selected order.
		 * @param array<int, int> $productIds Selected product IDs in actual display order
		 * @return self A list retaining source evidence and model information
		 * @throws \InvalidArgumentException When the selection exceeds the limit or names a product outside the pool
		 */
		public function selectDisplayedProducts(array $productIds): self {
			$selectionCount = count($productIds);
			
			if ($selectionCount > $this->limit) {
				throw new \InvalidArgumentException("Selection has {$selectionCount} items, but the list limit is {$this->limit}.");
			}
			
			$pool = [];
			
			foreach ($this->items as $item) {
				$pool[$item->productId] = $item;
			}
			
			$selected = [];
			$seen = [];
			
			foreach ($productIds as $id) {
				if (!is_int($id)) {
					throw new \InvalidArgumentException('Selected ID must be an integer, got ' . var_export($id, true) . '.');
				}
				
				if (!isset($pool[$id])) {
					throw new \InvalidArgumentException("Selected ID {$id} is outside the ranked pool.");
				}
				
				if (isset($seen[$id])) {
					throw new \InvalidArgumentException("Selected ID {$id} appears more than once.");
				}
				
				$seen[$id] = true;
				$selected[] = $pool[$id];
			}
			
			return new self($this->category, $this->placement, $this->sources, $this->contextKey,
				$this->scoreKind, $this->scorerId, $selected, $this->limit);
		}
		
		/**
		 * Return the canonical enabled-source mask.
		 * @return int Canonical enabled-source mask
		 */
		public function sourceMask(): int {
			return RecommendationSource::mask($this->sources);
		}
		
		/**
		 * Index the enabled sources by value, rejecting non-sources and duplicates.
		 * @param array<mixed> $sources Enabled sources
		 * @return array<string, true> Enabled source values as keys
		 * @throws \InvalidArgumentException When a source is not a RecommendationSource or is repeated
		 */
		private static function sourceSet(array $sources): array {
			$sourceSet = [];
			
			foreach ($sources as $source) {
				if (!$source instanceof RecommendationSource) {
					throw new \InvalidArgumentException('Sources must be RecommendationSource values, got ' . get_debug_type($source) . '.');
				}
				
				if (isset($sourceSet[$source->value])) {
					throw new \InvalidArgumentException("Source '{$source->value}' is listed more than once.");
				}
				
				$sourceSet[$source->value] = true;
			}
			
			return $sourceSet;
		}
		
		/**
		 * Reject items that are not unique recommendations, lack a score, or use a disabled source.
		 * @param array<mixed> $items Ordered results to check
		 * @param ScoreKind $scoreKind Score kind of the list
		 * @param array<string, true> $sourceSet Enabled source values
		 * @return void
		 * @throws \InvalidArgumentException When an item is invalid
		 */
		private static function validateItems(array $items, ScoreKind $scoreKind, array $sourceSet): void {
			$seen = [];
			
			foreach ($items as $item) {
				if (!$item instanceof ReconciledRecommendation) {
					throw new \InvalidArgumentException('Items must be ReconciledRecommendation instances, got ' . get_debug_type($item) . '.');
				}
				
				if (isset($seen[$item->productId])) {
					throw new \InvalidArgumentException("Product ID {$item->productId} appears more than once.");
				}
				
				if ($scoreKind !== ScoreKind::Direct && $item->rankingScore === null) {
					throw new \InvalidArgumentException("Product ID {$item->productId} needs a ranking score for score kind '{$scoreKind->value}'.");
				}
				
				$seen[$item->productId] = true;
				
				foreach ($item->evidence as $signal) {
					if (!isset($sourceSet[$signal->source->value])) {
						throw new \InvalidArgumentException("Product ID {$item->productId} has evidence from source '{$signal->source->value}', which is not enabled.");
					}
				}
			}
		}
	}
