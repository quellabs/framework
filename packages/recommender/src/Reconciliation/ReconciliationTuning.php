<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** Per-request overrides for reconciliation thresholds and source limits. */
	readonly class ReconciliationTuning {

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

		/**
		 * Store the overrides. Range validation happens in ReconciliationRequest.
		 * @param int $minSlopeSupport Minimum summed Slope One pair support
		 * @param int $topRatedMinRatings Minimum ratings for a top-rated candidate
		 * @param int $minNeighbourSimilarity Minimum neighbour similarity, from 1 to 100
		 * @param int $maxNeighbours Maximum neighbours used for user similarity
		 * @param int|null $maxCandidateDepth Maximum source depth override, at least 50
		 * @param int|null $maxBackfillRounds Maximum deeper-query rounds override, at least 1
		 * @param int|null $maxEligibilityBatchSize Maximum IDs per eligibility call override, at least 1
		 */
		public function __construct(
			int  $minSlopeSupport = 1,
			int  $topRatedMinRatings = 2,
			int  $minNeighbourSimilarity = 1,
			int  $maxNeighbours = 100,
			?int $maxCandidateDepth = null,
			?int $maxBackfillRounds = null,
			?int $maxEligibilityBatchSize = null
		) {
			$this->minSlopeSupport = $minSlopeSupport;
			$this->topRatedMinRatings = $topRatedMinRatings;
			$this->minNeighbourSimilarity = $minNeighbourSimilarity;
			$this->maxNeighbours = $maxNeighbours;
			$this->maxCandidateDepth = $maxCandidateDepth;
			$this->maxBackfillRounds = $maxBackfillRounds;
			$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;
		}
	}
