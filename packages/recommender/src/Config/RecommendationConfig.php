<?php
	
	namespace Quellabs\Recommender\Config;
	
	class RecommendationConfig {
		
		/**
		 * Build an immutable recommendation configuration.
		 * @param int $category Default category for all operations
		 * @param int $thresholdNrCommonRatings Minimum number of common ratings required before similarity is considered reliable
		 * @param int $thresholdMult Multiplier used in the similarity confidence calculation
		 * @param float $thresholdRating Minimum rating value for an item to count as "liked" in link/slope calculations
		 * @param float $cost Cost factor used in the similarity spread calculation
		 * @param float $notInterested Sentinel value stored in vogoo_ratings to mark "not interested"
		 * @param bool $directLinks Whether to maintain the item co-occurrence link table incrementally on every rating change
		 * @param bool $directSlope Whether to maintain the slope one diff table incrementally on every rating change
		 * @param int $maxCandidateDepth Maximum reconciliation source depth
		 * @param int $maxBackfillRounds Maximum deeper-query rounds
		 * @param int $maxEligibilityBatchSize Maximum IDs in one provider call
		 */
		public function __construct(
			// Default category for all operations
			private readonly int   $category = 1,
			
			// Minimum number of common ratings required before similarity is considered reliable
			private readonly int   $thresholdNrCommonRatings = 30,
			
			// Multiplier used in the similarity confidence calculation
			private readonly int   $thresholdMult = 2,
			
			// Minimum rating value for an item to count as "liked" in link/slope calculations
			private readonly float $thresholdRating = 0.66,
			
			// Cost factor used in the similarity spread calculation
			private readonly float $cost = 5.0,
			
			// Sentinel value stored in vogoo_ratings to mark "not interested"
			private readonly float $notInterested = -1.0,
			
			// Whether to maintain the item co-occurrence link table incrementally on every rating change
			private readonly bool  $directLinks = false,
			
			// Whether to maintain the slope one diff table incrementally on every rating change
			private readonly bool  $directSlope = true,
			private readonly int $maxCandidateDepth = 2000,
			private readonly int $maxBackfillRounds = 3,
			private readonly int $maxEligibilityBatchSize = 500,
		) {
			if ($category < 0 || $category > 4294967295 || $thresholdNrCommonRatings < 1 || $thresholdMult < 1
				|| !is_finite($thresholdRating) || $thresholdRating < 0.0 || $thresholdRating > 1.0
				|| !is_finite($cost) || $cost <= 0.0 || $notInterested !== -1.0
				|| $maxCandidateDepth < 50 || $maxBackfillRounds < 1 || $maxEligibilityBatchSize < 1) {
				throw new \InvalidArgumentException('Invalid recommender configuration.');
			}
		}

		/** @return int Maximum generated depth per source. */
		public function getMaxCandidateDepth(): int { return $this->maxCandidateDepth; }

		/** @return int Maximum deeper-query rounds. */
		public function getMaxBackfillRounds(): int { return $this->maxBackfillRounds; }

		/** @return int Maximum IDs submitted to one eligibility call. */
		public function getMaxEligibilityBatchSize(): int { return $this->maxEligibilityBatchSize; }
		
		/**
		 * Return the configured default category.
		 * @return int The configured default category
		 */
		public function getCategory(): int {
			return $this->category;
		}
		
		/**
		 * Return the minimum number of common ratings required before a similarity is considered reliable.
		 * @return int Minimum common ratings before a similarity is considered reliable
		 */
		public function getThresholdNrCommonRatings(): int {
			return $this->thresholdNrCommonRatings;
		}
		
		/**
		 * Return the multiplier used in the similarity confidence calculation.
		 * @return int Multiplier used in the similarity confidence calculation
		 */
		public function getThresholdMult(): int {
			return $this->thresholdMult;
		}
		
		/**
		 * Return the minimum rating for an item to count as "liked".
		 * @return float Minimum rating for an item to count as "liked"
		 */
		public function getThresholdRating(): float {
			return $this->thresholdRating;
		}
		
		/**
		 * Return the cost factor used in the similarity spread calculation.
		 * @return float Cost factor used in the similarity spread calculation
		 */
		public function getCost(): float {
			return $this->cost;
		}
		
		/**
		 * Return the sentinel rating value that marks "not interested".
		 * @return float Sentinel rating value marking "not interested"
		 */
		public function getNotInterested(): float {
			return $this->notInterested;
		}
		
		/**
		 * Whether the co-occurrence link table is maintained incrementally on every rating change.
		 * @return bool Whether the link table is maintained incrementally
		 */
		public function isDirectLinks(): bool {
			return $this->directLinks;
		}
		
		/**
		 * Whether the slope one diff table is maintained incrementally on every rating change.
		 * @return bool Whether the slope one table is maintained incrementally
		 */
		public function isDirectSlope(): bool {
			return $this->directSlope;
		}
		
		/**
		 * Resolve an optional category override against the configured default.
		 * All public methods accept ?int $category = null and call this internally.
		 * @param int|null $category Category override, or null to use the configured default
		 * @return int The resolved category
		 */
		public function resolveCategory(?int $category): int {
			$resolved = $category ?? $this->category;
			if ($resolved < 0 || $resolved > 4294967295) {
				throw new \InvalidArgumentException('Category must be an unsigned 32-bit integer.');
			}
			return $resolved;
		}
	}
