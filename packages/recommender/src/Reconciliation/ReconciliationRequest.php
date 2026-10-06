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
		 * @throws \InvalidArgumentException When an input is outside its allowed range
		 */
		public function __construct(
			EligibilityProvider   $eligibility,
			array                 $sources,
			int                   $limit,
			string                $placement,
			array                 $newProductIds = [],
			array                 $additionalCandidateIds = [],
			?int                  $category = null,
			?string               $contextKey = null,
			?ReconciliationTuning $tuning = null
		) {
			$this->eligibility = $eligibility;
			$this->limit = $limit;
			$this->placement = $placement;
			$this->category = $category;
			$this->contextKey = $contextKey;
			$this->tuning = $tuning ?? new ReconciliationTuning();
			$this->sources = self::canonicalSources($sources);
			
			$this->validateLimit();
			$this->validateCategory();
			self::validateKey($placement, 64, 'placement');
			
			if ($contextKey !== null) {
				self::validateKey($contextKey, 128, 'context');
			}
			
			$this->newProductIds = self::distinctIds($newProductIds);
			$this->additionalCandidateIds = self::distinctIds($additionalCandidateIds);
		}
		
		/**
		 * Validate a key as a non-empty printable ASCII string within a byte limit.
		 * @param string $value Key to validate
		 * @param int $maxLength Maximum byte length
		 * @param string $name Error context
		 * @return void
		 * @throws \InvalidArgumentException When the key is empty, too long, or not printable ASCII
		 */
		public static function validateKey(string $value, int $maxLength, string $name): void {
			if ($value === '' || strlen($value) > $maxLength || preg_match('/^[\x20-\x7e]+$/D', $value) !== 1) {
				throw new \InvalidArgumentException("Invalid {$name} key '{$value}': expected 1 to {$maxLength} printable ASCII characters.");
			}
		}
		
		/**
		 * Return the candidate IDs with duplicates removed, keeping first occurrences.
		 * @param array<int, int> $ids Candidate IDs
		 * @return array<int, int> First occurrences in input order
		 * @throws \InvalidArgumentException When an ID is not an unsigned 32-bit integer or there are too many distinct IDs
		 */
		public static function distinctIds(array $ids): array {
			$unique = [];
			
			foreach ($ids as $id) {
				if (!is_int($id) || $id < 0 || $id > Identifier::MAX) {
					throw new \InvalidArgumentException('Candidate ID must be an unsigned 32-bit integer, got ' . var_export($id, true) . '.');
				}
				
				$unique[$id] = $id;
			}
			
			$count = count($unique);
			
			if ($count > 500) {
				throw new \InvalidArgumentException("At most 500 distinct caller candidate IDs are allowed, got {$count}.");
			}
			
			return array_values($unique);
		}
		
		/**
		 * Return the canonical enabled-source mask.
		 * @return int Canonical enabled-source mask
		 */
		public function sourceMask(): int {
			return RecommendationSource::mask($this->sources);
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
