<?php
	
	namespace Quellabs\Recommender\Config;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	readonly class RecommendationConfig {

		/** @var float Sentinel rating stored in vogoo_ratings to mark not interested */
		public const float NOT_INTERESTED = -1.0;

		/** @var int Default category for all operations */
		private int $category;
		
		/** @var int Common ratings above which member similarity needs no confidence penalty */
		private int $thresholdNrCommonRatings;
		
		/** @var int Scales the common-rating requirement in the member similarity confidence penalty */
		private int $thresholdMult;
		
		/** @var float Minimum rating for an item to count as liked in link and slope calculations */
		private float $thresholdRating;
		
		/** @var float Scales squared rating differences into the member similarity spread */
		private float $cost;
		
		/** @var bool Whether the link table is maintained incrementally on every rating change */
		private bool $directLinks;
		
		/** @var bool Whether the slope one diff table is maintained incrementally on every rating change */
		private bool $directSlope;
		
		/** @var int Maximum reconciliation source depth */
		private int $maxCandidateDepth;
		
		/** @var int Maximum deeper-query rounds */
		private int $maxBackfillRounds;
		
		/** @var int Maximum IDs in one eligibility provider call */
		private int $maxEligibilityBatchSize;
		
		/**
		 * Build an immutable recommendation configuration.
		 * @param int $category Default category for all operations
		 * @param int $thresholdNrCommonRatings Common ratings above which member similarity needs no confidence penalty
		 * @param int $thresholdMult Scales the common-rating requirement in the member similarity confidence penalty
		 * @param float $thresholdRating Minimum rating for an item to count as liked
		 * @param float $cost Scales squared rating differences into the member similarity spread, greater than 0
		 * @param bool $directLinks Whether the link table is maintained incrementally
		 * @param bool $directSlope Whether the slope one diff table is maintained incrementally
		 * @param int $maxCandidateDepth Maximum reconciliation source depth, at least 50
		 * @param int $maxBackfillRounds Maximum deeper-query rounds, at least 1
		 * @param int $maxEligibilityBatchSize Maximum IDs in one eligibility provider call, at least 1
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(
			int                $category = 1,
			int                $thresholdNrCommonRatings = 30,
			int                $thresholdMult = 2,
			float              $thresholdRating = 0.66,
			float              $cost = 5.0,
			bool               $directLinks = false,
			bool               $directSlope = true,
			int                $maxCandidateDepth = 2000,
			int                $maxBackfillRounds = 3,
			int                $maxEligibilityBatchSize = 500
		) {
			$this->category = $category;
			$this->thresholdNrCommonRatings = $thresholdNrCommonRatings;
			$this->thresholdMult = $thresholdMult;
			$this->thresholdRating = $thresholdRating;
			$this->cost = $cost;
			$this->directLinks = $directLinks;
			$this->directSlope = $directSlope;
			$this->maxCandidateDepth = $maxCandidateDepth;
			$this->maxBackfillRounds = $maxBackfillRounds;
			$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;

			$this->validateRatingThresholds();
			$this->validateLimits();
		}
		
		/**
		 * Build a configuration from raw config values, using the constructor defaults for missing or invalid keys.
		 * @param array<string, mixed> $values Raw values keyed by config name, such as threshold_rating
		 * @return self
		 * @throws \InvalidArgumentException When a present value is outside its allowed range
		 */
		public static function fromArray(array $values): self {
			$defaults = new self();

			return new self(
				category: self::intValue($values, 'category', $defaults->category),
				thresholdNrCommonRatings: self::intValue($values, 'threshold_nr_common_ratings', $defaults->thresholdNrCommonRatings),
				thresholdMult: self::intValue($values, 'threshold_mult', $defaults->thresholdMult),
				thresholdRating: self::floatValue($values, 'threshold_rating', $defaults->thresholdRating),
				cost: self::floatValue($values, 'cost', $defaults->cost),
				directLinks: self::boolValue($values, 'direct_links', $defaults->directLinks),
				directSlope: self::boolValue($values, 'direct_slope', $defaults->directSlope),
				maxCandidateDepth: self::intValue($values, 'max_candidate_depth', $defaults->maxCandidateDepth),
				maxBackfillRounds: self::intValue($values, 'max_backfill_rounds', $defaults->maxBackfillRounds),
				maxEligibilityBatchSize: self::intValue($values, 'max_eligibility_batch_size', $defaults->maxEligibilityBatchSize),
			);
		}
	
		/**
		 * Return the maximum generated depth per source.
		 * @return int Maximum generated depth per source
		 */
		public function getMaxCandidateDepth(): int {
			return $this->maxCandidateDepth;
		}
		
		/**
		 * Return the maximum number of deeper-query rounds.
		 * @return int Maximum deeper-query rounds
		 */
		public function getMaxBackfillRounds(): int {
			return $this->maxBackfillRounds;
		}
		
		/**
		 * Return the maximum number of IDs submitted to one eligibility call.
		 * @return int Maximum IDs submitted to one eligibility call
		 */
		public function getMaxEligibilityBatchSize(): int {
			return $this->maxEligibilityBatchSize;
		}
		
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
		 * Return the minimum rating for an item to count as liked.
		 * @return float Minimum rating for an item to count as liked
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
		 * Return the sentinel rating value that marks not interested.
		 * @return float Sentinel rating value marking not interested
		 */
		public function getNotInterested(): float {
			return self::NOT_INTERESTED;
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
		 * @param int|null $category Category override, or null to use the configured default
		 * @return int The resolved category
		 * @throws \InvalidArgumentException When the resolved category is outside the unsigned 32-bit range
		 */
		public function resolveCategory(?int $category): int {
			$resolved = $category ?? $this->category;
			
			if ($resolved < 0 || $resolved > Identifier::MAX) {
				throw new \InvalidArgumentException("Category must be an unsigned 32-bit integer, got {$resolved}.");
			}
			
			return $resolved;
		}
		
		/**
		 * Read an integer value, falling back to the default when the key is missing or non-numeric.
		 * @param array<string, mixed> $values Raw config values
		 * @param string $key Config key
		 * @param int $default Fallback value
		 * @return int
		 */
		private static function intValue(array $values, string $key, int $default): int {
			return isset($values[$key]) && is_numeric($values[$key]) ? (int)$values[$key] : $default;
		}
	
		/**
		 * Read a float value, falling back to the default when the key is missing or non-numeric.
		 * @param array<string, mixed> $values Raw config values
		 * @param string $key Config key
		 * @param float $default Fallback value
		 * @return float
		 */
		private static function floatValue(array $values, string $key, float $default): float {
			return isset($values[$key]) && is_numeric($values[$key]) ? (float)$values[$key] : $default;
		}
	
		/**
		 * Read a boolean value, parsing string forms such as "false" and "0".
		 * @param array<string, mixed> $values Raw config values
		 * @param string $key Config key
		 * @param bool $default Fallback value when the key is missing or a string is not a recognized boolean
		 * @return bool
		 */
		private static function boolValue(array $values, string $key, bool $default): bool {
			if (!isset($values[$key])) {
				return $default;
			}
	
			if (is_string($values[$key])) {
				return filter_var($values[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
			}
	
			return (bool)$values[$key];
		}
	
		/**
		 * Reject rating, cost and sentinel values outside their allowed ranges.
		 * @return void
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		private function validateRatingThresholds(): void {
			if (!is_finite($this->thresholdRating) || $this->thresholdRating < 0.0 || $this->thresholdRating > 1.0) {
				throw new \InvalidArgumentException("Threshold rating must be a finite value between 0 and 1, got {$this->thresholdRating}.");
			}
			
			if (!is_finite($this->cost) || $this->cost <= 0.0) {
				throw new \InvalidArgumentException("Cost must be a finite value greater than 0, got {$this->cost}.");
			}
		}
		
		/**
		 * Reject category and count limits outside their allowed ranges.
		 * @return void
		 * @throws \InvalidArgumentException When a limit is outside its allowed range
		 */
		private function validateLimits(): void {
			if ($this->category < 0 || $this->category > Identifier::MAX) {
				throw new \InvalidArgumentException("Category must be an unsigned 32-bit integer, got {$this->category}.");
			}
			
			if ($this->thresholdNrCommonRatings < 1) {
				throw new \InvalidArgumentException("Threshold nr common ratings must be at least 1, got {$this->thresholdNrCommonRatings}.");
			}
			
			if ($this->thresholdMult < 1) {
				throw new \InvalidArgumentException("Threshold mult must be at least 1, got {$this->thresholdMult}.");
			}
			
			if ($this->maxCandidateDepth < 50) {
				throw new \InvalidArgumentException("Max candidate depth must be at least 50, got {$this->maxCandidateDepth}.");
			}
			
			if ($this->maxBackfillRounds < 1) {
				throw new \InvalidArgumentException("Max backfill rounds must be at least 1, got {$this->maxBackfillRounds}.");
			}
			
			if ($this->maxEligibilityBatchSize < 1) {
				throw new \InvalidArgumentException("Max eligibility batch size must be at least 1, got {$this->maxEligibilityBatchSize}.");
			}
		}
	}
