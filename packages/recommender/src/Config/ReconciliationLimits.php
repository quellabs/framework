<?php

	namespace Quellabs\Recommender\Config;

	/** Default upper bounds for reconciliation source depth, backfill rounds and eligibility batches. */
	readonly class ReconciliationLimits {

		/** @var int Maximum reconciliation source depth */
		public int $maxCandidateDepth;

		/** @var int Maximum deeper-query rounds */
		public int $maxBackfillRounds;

		/** @var int Maximum IDs in one eligibility provider call */
		public int $maxEligibilityBatchSize;

		/**
		 * Store the default reconciliation limits. Range validation happens in RecommendationConfig.
		 * @param int $maxCandidateDepth Maximum reconciliation source depth, at least 50
		 * @param int $maxBackfillRounds Maximum deeper-query rounds, at least 1
		 * @param int $maxEligibilityBatchSize Maximum IDs in one eligibility provider call, at least 1
		 */
		public function __construct(
			int $maxCandidateDepth = 2000,
			int $maxBackfillRounds = 3,
			int $maxEligibilityBatchSize = 500
		) {
			$this->maxCandidateDepth = $maxCandidateDepth;
			$this->maxBackfillRounds = $maxBackfillRounds;
			$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;
		}
	}
