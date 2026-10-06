<?php

	namespace Quellabs\Recommender\Reconciliation;

	use Quellabs\Recommender\Internal\Identifier;

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

		/** @var int Minimum ratings before personal sources are used; below it only the top-rated source runs */
		public int $minHistory;

		/**
		 * Store the overrides after validating each one.
		 * @param int $minSupport Minimum summed Slope One pair support, at least 1
		 * @param int $topRatedMinRatings Minimum ratings for a top-rated candidate, at least 1
		 * @param int $minNeighbourSimilarity Minimum neighbour similarity, from 1 to 100
		 * @param int $maxNeighbours Maximum neighbours used for user similarity, at least 1
		 * @param int|null $maxCandidateDepth Maximum source depth override, at least 50, or null for the configured default
		 * @param int|null $maxBackfillRounds Maximum deeper-query rounds override, at least 1, or null for the configured default
		 * @param int|null $maxEligibilityBatchSize Maximum IDs per eligibility call override, at least 1, or null for the configured default
		 * @param int $minHistory Non-negative ratings needed before personal sources run, at least 1
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(
			int  $minSupport = 1,
			int  $topRatedMinRatings = 2,
			int  $minNeighbourSimilarity = 1,
			int  $maxNeighbours = 100,
			?int $maxCandidateDepth = null,
			?int $maxBackfillRounds = null,
			?int $maxEligibilityBatchSize = null,
			int  $minHistory = 1
		) {
			Identifier::assertAtLeast($minSupport, 1, 'Minimum support');
			Identifier::assertAtLeast($topRatedMinRatings, 1, 'Minimum ratings');
			Identifier::assertInRange($minNeighbourSimilarity, 1, 100, 'Minimum neighbour similarity');
			Identifier::assertAtLeast($maxNeighbours, 1, 'Maximum neighbours');
			if ($maxCandidateDepth !== null) {
				Identifier::assertAtLeast($maxCandidateDepth, 50, 'Maximum candidate depth');
			}

			if ($maxBackfillRounds !== null) {
				Identifier::assertAtLeast($maxBackfillRounds, 1, 'Maximum backfill rounds');
			}

			if ($maxEligibilityBatchSize !== null) {
				Identifier::assertAtLeast($maxEligibilityBatchSize, 1, 'Maximum eligibility batch size');
			}

			Identifier::assertAtLeast($minHistory, 1, 'Minimum history');

			$this->minSupport = $minSupport;
			$this->topRatedMinRatings = $topRatedMinRatings;
			$this->minNeighbourSimilarity = $minNeighbourSimilarity;
			$this->maxNeighbours = $maxNeighbours;
			$this->maxCandidateDepth = $maxCandidateDepth;
			$this->maxBackfillRounds = $maxBackfillRounds;
			$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;
			$this->minHistory = $minHistory;
		}
	}
