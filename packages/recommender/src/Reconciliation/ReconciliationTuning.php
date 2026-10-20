<?php
	
	namespace Quellabs\Recommender\Reconciliation;

	use Quellabs\Recommender\Internal\Identifier;
	use Quellabs\Recommender\RecommendationSource;

	/** Per-request reconciliation settings: source settings, plus the depth, backfill and cold-start limits, validated on construction. */
	readonly class ReconciliationTuning {

		/** @var SourceSettings Settings read by the candidate sources */
		public SourceSettings $sources;

		/** @var int|null Maximum source depth override, or null for the configured default */
		public ?int $maxCandidateDepth;

		/** @var int|null Maximum IDs per eligibility call override, or null for the configured default */
		public ?int $maxEligibilityBatchSize;

		/** @var int Minimum ratings before personal sources are used; below it only the top-rated source runs */
		public int $minHistory;

		/** @var array<int, RecommendationSource>|null Fixed cold-start source set, or null to keep the request's own non-personal sources */
		public ?array $coldStartSources;

		/**
		 * Store the overrides after validating each one.
		 * @param SourceSettings|null $sources Source settings, defaults when null
		 * @param int|null $maxCandidateDepth Maximum source depth override, at least 50, or null for the configured default
		 * @param int|null $maxEligibilityBatchSize Maximum IDs per eligibility call override, at least 1, or null for the configured default
		 * @param int $minHistory Non-negative ratings needed before personal sources run, at least 1
		 * @param array<int, RecommendationSource>|null $coldStartSources Sources to use below minHistory, or null to keep the request's own non-personal sources (TopRated, NewProducts), falling back to TopRated alone when neither was requested
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(?SourceSettings $sources = null, ?int $maxCandidateDepth = null, ?int $maxEligibilityBatchSize = null, int $minHistory = 1, ?array $coldStartSources = null) {
			if ($maxCandidateDepth !== null) {
				Identifier::assertAtLeast($maxCandidateDepth, 50, 'Maximum candidate depth');
			}

			if ($maxEligibilityBatchSize !== null) {
				Identifier::assertAtLeast($maxEligibilityBatchSize, 1, 'Maximum eligibility batch size');
			}

			Identifier::assertAtLeast($minHistory, 1, 'Minimum history');

			if ($coldStartSources !== null) {
				self::assertValidSources($coldStartSources);
			}

			$this->sources = $sources ?? new SourceSettings();
			$this->maxCandidateDepth = $maxCandidateDepth;
			$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;
			$this->minHistory = $minHistory;
			$this->coldStartSources = $coldStartSources;
		}

		/**
		 * Reject an empty or malformed cold-start source list.
		 * @param array<mixed> $sources Cold-start sources as given to the constructor
		 * @return void
		 * @throws \InvalidArgumentException When the list is empty or contains a non-source
		 */
		private static function assertValidSources(array $sources): void {
			if ($sources === []) {
				throw new \InvalidArgumentException('Cold-start sources must not be empty when given.');
			}

			foreach ($sources as $source) {
				if (!$source instanceof RecommendationSource) {
					throw new \InvalidArgumentException('Cold-start sources must be RecommendationSource values, got ' . get_debug_type($source) . '.');
				}
			}
		}
	}
