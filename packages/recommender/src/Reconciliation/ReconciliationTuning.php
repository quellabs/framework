<?php

	namespace Quellabs\Recommender\Reconciliation;

	use Quellabs\Recommender\MinRatings;
	use Quellabs\Recommender\MinSupport;

	/** Per-request overrides for reconciliation thresholds and source limits, validated on construction. */
	readonly class ReconciliationTuning {

		/** @var int Minimum summed Slope One pair support */
		public int $minSupport;

		/** @var int Minimum ratings for a top-rated candidate */
		public int $topRatedMinRatings;

		/** @var int Minimum neighbour similarity, from 1 to 100 */
		public int $minNeighbourSimilarity;

		/** @var int Maximum neighbours used for user similarity */
		public int $maxNeighbours;

		/** @var int|null Maximum source depth override, or null for the configured default */
		public ?int $maxCandidateDepth;

		/** @var int|null Maximum deeper-query rounds override, or null for the configured default */
		public ?int $maxBackfillRounds;

		/** @var int|null Maximum IDs per eligibility call override, or null for the configured default */
		public ?int $maxEligibilityBatchSize;

		/**
		 * Store the overrides, validating each through its value object.
		 * @param MinSupport $minSupport Minimum summed Slope One pair support
		 * @param MinRatings $topRatedMinRatings Minimum ratings for a top-rated candidate
		 * @param MinSimilarity $minNeighbourSimilarity Minimum neighbour similarity, from 1 to 100
		 * @param NeighbourLimit $maxNeighbours Maximum neighbours used for user similarity
		 * @param SourceDepth|null $maxCandidateDepth Maximum source depth override, or null for the configured default
		 * @param BackfillRounds|null $maxBackfillRounds Maximum deeper-query rounds override, or null for the configured default
		 * @param EligibilityBatchSize|null $maxEligibilityBatchSize Maximum IDs per eligibility call override, or null for the configured default
		 */
		public function __construct(
			MinSupport          $minSupport = new MinSupport(),
			MinRatings          $topRatedMinRatings = new MinRatings(2),
			MinSimilarity       $minNeighbourSimilarity = new MinSimilarity(1),
			NeighbourLimit      $maxNeighbours = new NeighbourLimit(100),
			?SourceDepth        $maxCandidateDepth = null,
			?BackfillRounds     $maxBackfillRounds = null,
			?EligibilityBatchSize $maxEligibilityBatchSize = null
		) {
			$this->minSupport = $minSupport->value;
			$this->topRatedMinRatings = $topRatedMinRatings->value;
			$this->minNeighbourSimilarity = $minNeighbourSimilarity->value;
			$this->maxNeighbours = $maxNeighbours->value;
			$this->maxCandidateDepth = $maxCandidateDepth?->value;
			$this->maxBackfillRounds = $maxBackfillRounds?->value;
			$this->maxEligibilityBatchSize = $maxEligibilityBatchSize?->value;
		}
	}
