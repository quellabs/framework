<?php
	
	namespace Quellabs\Recommender;
	
	/** Immutable ordered candidates or the application's selected display. */
	readonly class RecommendationList {
		/** @param int $category Resolved category
		 * @param string $placement Display surface
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param string|null $contextKey Model and logging partition
		 * @param string $scoreKind direct, rank_fusion, or click_probability
		 * @param string|null $modelId Active model token, when calibrated
		 * @param array<int, ReconciledRecommendation> $items Ordered results
		 * @param int $limit Maximum selectable displayed items
		 */
		public function __construct(
			public int $category,
			public string $placement,
			public array $sources,
			public ?string $contextKey,
			public string $scoreKind,
			public ?string $modelId,
			public array $items,
			public int $limit,
		) {
			if ($category < 0 || $category > 4294967295 || $limit < 1 || $limit > 100
				|| !in_array($scoreKind, ['direct', 'rank_fusion', 'click_probability'], true)
				|| ($scoreKind === 'click_probability') !== ($modelId !== null)) {
				throw new \InvalidArgumentException('Invalid recommendation list metadata.');
			}
			ReconciliationRequest::validateKey($placement, 64, 'placement');
			if ($contextKey !== null) {
				ReconciliationRequest::validateKey($contextKey, 128, 'context');
			}
			if ($modelId !== null && preg_match('/^[0-9a-f]{32}$/D', $modelId) !== 1) {
				throw new \InvalidArgumentException('Invalid model ID.');
			}
			$sourceSet = [];
			foreach ($sources as $source) {
				if (!$source instanceof RecommendationSource || isset($sourceSet[$source->value])) {
					throw new \InvalidArgumentException('Sources must be distinct enum values.');
				}
				$sourceSet[$source->value] = true;
			}
			$seen = [];
			foreach ($items as $item) {
				if (!$item instanceof ReconciledRecommendation || isset($seen[$item->itemId])
					|| ($scoreKind !== 'direct' && $item->rankingScore === null)) {
					throw new \InvalidArgumentException('Invalid or duplicate recommendation item.');
				}
				$seen[$item->itemId] = true;
				foreach ($item->evidence as $signal) {
					if (!isset($sourceSet[$signal->source->value])) {
						throw new \InvalidArgumentException('Item evidence must belong to an enabled source.');
					}
				}
			}
		}
		
		/** @param int $category Resolved category
		 * @param string $placement Display surface
		 * @param array<int, ReconciledRecommendation> $items Displayed items in order
		 * @param string|null $contextKey Optional model partition
		 * @return self Direct display list
		 */
		public static function fromDisplayedItems(int $category, string $placement, array $items, ?string $contextKey = null): self {
			$sources = [];
			foreach ($items as $item) {
				if (!$item instanceof ReconciledRecommendation) {
					throw new \InvalidArgumentException('Displayed items must be recommendations.');
				}
				foreach ($item->evidence as $signal) {
					$sources[$signal->source->value] = $signal->source;
				}
			}
			return new self($category, $placement, array_values($sources), $contextKey, 'direct', null,
				$items, max(1, count($items)));
		}
		
		/** @param array<int, int> $itemIds Selected IDs in actual display order
		 * @return self A list retaining source evidence and model information
		 */
		public function selectDisplayedIds(array $itemIds): self {
			if (count($itemIds) > $this->limit) {
				throw new \InvalidArgumentException('Invalid displayed item selection.');
			}
			$pool = [];
			foreach ($this->items as $item) {
				$pool[$item->itemId] = $item;
			}
			$selected = [];
			$seen = [];
			foreach ($itemIds as $id) {
				if (!is_int($id) || !isset($pool[$id]) || isset($seen[$id])) {
					throw new \InvalidArgumentException('Displayed item is outside the ranked pool.');
				}
				$seen[$id] = true;
				$selected[] = $pool[$id];
			}
			return new self($this->category, $this->placement, $this->sources, $this->contextKey,
				$this->scoreKind, $this->modelId, $selected, $this->limit);
		}
		
		/** @return int Canonical enabled-source mask. */
		public function sourceMask(): int {
			return array_reduce($this->sources, fn($mask, $source) => $mask | $source->bit(), 0);
		}
	}
