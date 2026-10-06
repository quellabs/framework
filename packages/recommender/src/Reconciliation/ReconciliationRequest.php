<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	use Quellabs\Recommender\EligibilityProvider;
	use Quellabs\Recommender\Internal\Identifier;
	
	
	use Quellabs\Recommender\RecommendationSource;
	/** Validated inputs for one optional reconciliation request. */
	readonly class ReconciliationRequest {
		
		/** @var EligibilityProvider Application eligibility check */
		public EligibilityProvider $eligibility;
		
		/** @var array<int, RecommendationSource> Enabled sources in canonical bit order */
		public array $sources;
		
		/** @var int Maximum selectable items, from 1 to 100 */
		public int $limit;
		
		/** @var string Display surface */
		public string $placement;
		
		/** @var int|null Category override */
		public ?int $category;
		
		/** @var ReconciliationTuning Threshold and source limit overrides */
		public ReconciliationTuning $tuning;
		
		/** @var string|null Model and logging partition */
		public ?string $contextKey;
		
		/** @var array<int, int> Ordered new-product suggestions */
		public array $newProductIds;
		
		/** @var array<int, int> Application exploration candidates */
		public array $additionalCandidateIds;
		
		/** @var bool Attach serving-time diagnostics to each item, required to record its impression */
		public bool $diagnostics;
		
		/**
		 * Validate the request inputs and store them in canonical form.
		 * @param EligibilityProvider $eligibility Application eligibility check
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param int $limit Maximum selectable items, from 1 to 100
		 * @param string $placement Display surface, a printable ASCII key up to 64 bytes
		 * @param array<int, int> $newProductIds Ordered new-product suggestions
		 * @param array<int, int> $additionalCandidateIds Application exploration candidates
		 * @param int|null $category Category override
		 * @param string|null $contextKey Model and logging partition, a printable ASCII key up to 128 bytes
		 * @param ReconciliationTuning|null $tuning Threshold and source limit overrides, defaults when null
		 * @param bool $diagnostics Attach serving-time diagnostics to each item
		 * @throws \InvalidArgumentException When an input is outside its allowed range
		 */
		public function __construct(EligibilityProvider $eligibility, array $sources, int $limit, string $placement, array $newProductIds = [], array $additionalCandidateIds = [], ?int $category = null, ?string $contextKey = null, ?ReconciliationTuning $tuning = null, bool $diagnostics = false) {
			$this->eligibility = $eligibility;
			$this->limit = $limit;
			$this->placement = $placement;
			$this->category = $category;
			$this->contextKey = $contextKey;
			$this->tuning = $tuning ?? new ReconciliationTuning();
			$this->diagnostics = $diagnostics;
			$this->sources = self::canonicalSources($sources);
			
			$this->validateLimit();
			$this->validateCategory();
			Identifier::validateKey($placement, 64, 'placement');
			
			if ($contextKey !== null) {
				Identifier::validateKey($contextKey, 128, 'context');
			}
			
			$this->newProductIds = Identifier::distinctIds($newProductIds);
			$this->additionalCandidateIds = Identifier::distinctIds($additionalCandidateIds);
		}
		
		/**
		 * Return a copy of this request with a different source set.
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @return self Request with the same inputs and the given sources
		 * @throws \InvalidArgumentException When the source set is empty, repeats a source, or contains a non-source
		 */
		public function withSources(array $sources): self {
			return new self($this->eligibility, $sources, $this->limit, $this->placement,
				$this->newProductIds, $this->additionalCandidateIds, $this->category, $this->contextKey, $this->tuning,
				$this->diagnostics);
		}

		/**
		 * Validate and deduplicate sources, returning them in canonical bit order.
		 * @param array<mixed> $sources Requested sources
		 * @return array<int, RecommendationSource> Distinct sources ordered by bit
		 * @throws \InvalidArgumentException When the list is empty, contains a non-source, or repeats a source
		 */
		private static function canonicalSources(array $sources): array {
			if ($sources === []) {
				throw new \InvalidArgumentException('At least one recommendation source is required.');
			}
			
			$unique = [];
			
			foreach ($sources as $source) {
				if (!$source instanceof RecommendationSource) {
					throw new \InvalidArgumentException('Sources must be RecommendationSource values, got ' . get_debug_type($source) . '.');
				}
				
				if (isset($unique[$source->value])) {
					throw new \InvalidArgumentException("Source '{$source->value}' is listed more than once.");
				}
				
				$unique[$source->value] = $source;
			}
			
			$canonical = array_values($unique);
			usort($canonical, fn($a, $b) => $a->bit() <=> $b->bit());
			return $canonical;
		}
		
		/**
		 * Reject a limit outside its allowed range.
		 * @return void
		 * @throws \InvalidArgumentException When the limit is outside 1 to 100
		 */
		private function validateLimit(): void {
			if ($this->limit < 1 || $this->limit > 100) {
				throw new \InvalidArgumentException("Limit must be between 1 and 100, got {$this->limit}.");
			}
		}
		
		/**
		 * Reject a category outside the unsigned 32-bit range.
		 * @return void
		 * @throws \InvalidArgumentException When the category is outside the unsigned 32-bit range
		 */
		private function validateCategory(): void {
			if ($this->category !== null && ($this->category < 0 || $this->category > Identifier::MAX)) {
				throw new \InvalidArgumentException("Category must be an unsigned 32-bit integer, got {$this->category}.");
			}
		}
	}
