<?php

namespace Quellabs\Recommender;

use Quellabs\Recommender\Internal\Identifier;

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
	
	/** @var int Minimum summed Slope One pair support */
	public int $minSlopeSupport;
	
	/** @var int Minimum ratings for a top-rated candidate */
	public int $topRatedMinRatings;
	
	/** @var int Minimum neighbour similarity, from 1 to 100 */
	public int $minNeighbourSimilarity;
	
	/** @var int Maximum neighbours used for user similarity */
	public int $maxNeighbours;
	
	/** @var int|null Maximum source depth override */
	public ?int $maxCandidateDepth;
	
	/** @var int|null Maximum deeper-query rounds override */
	public ?int $maxBackfillRounds;
	
	/** @var int|null Maximum IDs per eligibility call override */
	public ?int $maxEligibilityBatchSize;
	
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
	 * @param int $minSlopeSupport Minimum summed Slope One pair support
	 * @param int $topRatedMinRatings Minimum ratings for a top-rated candidate
	 * @param int $minNeighbourSimilarity Minimum neighbour similarity, from 1 to 100
	 * @param int $maxNeighbours Maximum neighbours used for user similarity
	 * @param int|null $maxCandidateDepth Maximum source depth override, at least 50
	 * @param int|null $maxBackfillRounds Maximum deeper-query rounds override, at least 1
	 * @param int|null $maxEligibilityBatchSize Maximum IDs per eligibility call override, at least 1
	 * @param string|null $contextKey Model and logging partition, a printable ASCII key up to 128 bytes
	 * @throws \InvalidArgumentException When an input is outside its allowed range
	 */
	public function __construct(
		EligibilityProvider $eligibility,
		array               $sources,
		int                 $limit,
		string              $placement,
		array               $newProductIds = [],
		array               $additionalCandidateIds = [],
		?int                $category = null,
		int                 $minSlopeSupport = 1,
		int                 $topRatedMinRatings = 2,
		int                 $minNeighbourSimilarity = 1,
		int                 $maxNeighbours = 100,
		?int                $maxCandidateDepth = null,
		?int                $maxBackfillRounds = null,
		?int                $maxEligibilityBatchSize = null,
		?string             $contextKey = null
	) {
		$this->eligibility = $eligibility;
		$this->limit = $limit;
		$this->placement = $placement;
		$this->category = $category;
		$this->minSlopeSupport = $minSlopeSupport;
		$this->topRatedMinRatings = $topRatedMinRatings;
		$this->minNeighbourSimilarity = $minNeighbourSimilarity;
		$this->maxNeighbours = $maxNeighbours;
		$this->maxCandidateDepth = $maxCandidateDepth;
		$this->maxBackfillRounds = $maxBackfillRounds;
		$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;
		$this->contextKey = $contextKey;
		$this->sources = self::canonicalSources($sources);
		
		$this->validateLimits();
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
		return array_reduce($this->sources, fn($mask, $source) => $mask | $source->bit(), 0);
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
	 * Reject numeric limits and the category when they are outside their allowed ranges.
	 * @return void
	 * @throws \InvalidArgumentException When a limit or the category is outside its allowed range
	 */
	private function validateLimits(): void {
		if ($this->limit < 1 || $this->limit > 100) {
			throw new \InvalidArgumentException("Limit must be between 1 and 100, got {$this->limit}.");
		}
		
		if ($this->minSlopeSupport < 1) {
			throw new \InvalidArgumentException("Minimum slope support must be at least 1, got {$this->minSlopeSupport}.");
		}
		
		if ($this->topRatedMinRatings < 1) {
			throw new \InvalidArgumentException("Top-rated minimum ratings must be at least 1, got {$this->topRatedMinRatings}.");
		}
		
		if ($this->minNeighbourSimilarity < 1 || $this->minNeighbourSimilarity > 100) {
			throw new \InvalidArgumentException("Minimum neighbour similarity must be between 1 and 100, got {$this->minNeighbourSimilarity}.");
		}
		
		if ($this->maxNeighbours < 1) {
			throw new \InvalidArgumentException("Maximum neighbours must be at least 1, got {$this->maxNeighbours}.");
		}
		
		if ($this->maxCandidateDepth !== null && $this->maxCandidateDepth < 50) {
			throw new \InvalidArgumentException("Maximum candidate depth must be at least 50, got {$this->maxCandidateDepth}.");
		}
		
		if ($this->maxBackfillRounds !== null && $this->maxBackfillRounds < 1) {
			throw new \InvalidArgumentException("Maximum backfill rounds must be at least 1, got {$this->maxBackfillRounds}.");
		}
		
		if ($this->maxEligibilityBatchSize !== null && $this->maxEligibilityBatchSize < 1) {
			throw new \InvalidArgumentException("Maximum eligibility batch size must be at least 1, got {$this->maxEligibilityBatchSize}.");
		}
		
		if ($this->category !== null && ($this->category < 0 || $this->category > Identifier::MAX)) {
			throw new \InvalidArgumentException("Category must be an unsigned 32-bit integer, got {$this->category}.");
		}
	}
}
