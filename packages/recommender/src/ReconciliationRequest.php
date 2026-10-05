<?php
	
	namespace Quellabs\Recommender;
	
	/** Validated inputs for one optional reconciliation request. */
	readonly class ReconciliationRequest {
		/** @var array<int, RecommendationSource> */
		public array $sources;
		/** @var array<int, int> */
		public array $newProductIds;
		/** @var array<int, int> */
		public array $additionalCandidateIds;
		
		/** @param array<int, RecommendationSource> $sources Enabled source set
		 * @param array<int, int> $newProductIds Ordered new-product suggestions
		 * @param array<int, int> $additionalCandidateIds Application exploration candidates
		 */
		public function __construct(
			public EligibilityProvider $eligibility,
			array $sources,
			public int $limit,
			public string $placement,
			array $newProductIds = [],
			array $additionalCandidateIds = [],
			public ?int $category = null,
			public int $minSlopeSupport = 1,
			public int $topRatedMinRatings = 2,
			public int $minNeighbourSimilarity = 1,
			public int $maxNeighbours = 100,
			public ?int $maxCandidateDepth = null,
			public ?int $maxBackfillRounds = null,
			public ?int $maxEligibilityBatchSize = null,
			public ?string $contextKey = null,
		) {
			if ($sources === [] || $limit < 1 || $limit > 100 || $minSlopeSupport < 1
				|| $topRatedMinRatings < 1 || $minNeighbourSimilarity < 1 || $minNeighbourSimilarity > 100
				|| $maxNeighbours < 1 || ($maxCandidateDepth !== null && $maxCandidateDepth < 50)
				|| ($maxBackfillRounds !== null && $maxBackfillRounds < 1)
				|| ($maxEligibilityBatchSize !== null && $maxEligibilityBatchSize < 1)
				|| ($category !== null && ($category < 0 || $category > 4294967295))) {
				throw new \InvalidArgumentException('Invalid reconciliation limits or category.');
			}
			self::validateKey($placement, 64, 'placement');
			if ($contextKey !== null) {
				self::validateKey($contextKey, 128, 'context');
			}
			$unique = [];
			foreach ($sources as $source) {
				if (!$source instanceof RecommendationSource || isset($unique[$source->value])) {
					throw new \InvalidArgumentException('Sources must be distinct RecommendationSource values.');
				}
				$unique[$source->value] = $source;
			}
			$canonicalSources = array_values($unique);
			usort($canonicalSources, fn($a, $b) => $a->bit() <=> $b->bit());
			$this->sources = $canonicalSources;
			$this->newProductIds = self::distinctIds($newProductIds);
			$this->additionalCandidateIds = self::distinctIds($additionalCandidateIds);
		}
		
		/** @param string $value Key to validate
		 * @param int $maxLength Maximum byte length
		 * @param string $name Error context
		 * @return void
		 */
		public static function validateKey(string $value, int $maxLength, string $name): void {
			if ($value === '' || strlen($value) > $maxLength || preg_match('/^[\x20-\x7e]+$/D', $value) !== 1) {
				throw new \InvalidArgumentException("Invalid {$name} key.");
			}
		}
		
		/** @param array<int, int> $ids Candidate IDs
		 * @return array<int, int> First occurrences in input order
		 */
		public static function distinctIds(array $ids): array {
			$unique = [];
			foreach ($ids as $id) {
				if (!is_int($id) || $id < 0 || $id > 4294967295) {
					throw new \InvalidArgumentException('Candidate IDs must be unsigned 32-bit integers.');
				}
				$unique[$id] = $id;
			}
			if (count($unique) > 500) {
				throw new \InvalidArgumentException('At most 500 distinct caller candidate IDs are allowed.');
			}
			return array_values($unique);
		}
		
		/** @return int Canonical enabled-source mask. */
		public function sourceMask(): int {
			return array_reduce($this->sources, fn($mask, $source) => $mask | $source->bit(), 0);
		}
	}
