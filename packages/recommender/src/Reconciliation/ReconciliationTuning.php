<?php

	namespace Quellabs\Recommender\Reconciliation;

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
		 * Store and validate the overrides.
		 * @param int $minSupport Minimum summed Slope One pair support, at least 1
		 * @param int $topRatedMinRatings Minimum ratings for a top-rated candidate, at least 1
		 * @param int $minNeighbourSimilarity Minimum neighbour similarity, from 1 to 100
		 * @param int $maxNeighbours Maximum neighbours used for user similarity, at least 1
		 * @param int|null $maxCandidateDepth Maximum source depth override, at least 50
		 * @param int|null $maxBackfillRounds Maximum deeper-query rounds override, at least 1
		 * @param int|null $maxEligibilityBatchSize Maximum IDs per eligibility call override, at least 1
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(
			int  $minSupport = 1,
			int  $topRatedMinRatings = 2,
			int  $minNeighbourSimilarity = 1,
			int  $maxNeighbours = 100,
			?int $maxCandidateDepth = null,
			?int $maxBackfillRounds = null,
			?int $maxEligibilityBatchSize = null
		) {
			$this->minSupport = $minSupport;
			$this->topRatedMinRatings = $topRatedMinRatings;
			$this->minNeighbourSimilarity = $minNeighbourSimilarity;
			$this->maxNeighbours = $maxNeighbours;
			$this->maxCandidateDepth = $maxCandidateDepth;
			$this->maxBackfillRounds = $maxBackfillRounds;
			$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;

			$this->validateThresholds();
			$this->validateOverrides();
		}

		/**
		 * Reject thresholds and neighbour settings outside their allowed ranges.
		 * @return void
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		private function validateThresholds(): void {
			if ($this->minSupport < 1) {
				throw new \InvalidArgumentException("Minimum slope support must be at least 1, got {$this->minSupport}.");
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
		}

		/**
		 * Reject optional source-depth, backfill and batch overrides outside their allowed ranges.
		 * @return void
		 * @throws \InvalidArgumentException When an override is outside its allowed range
		 */
		private function validateOverrides(): void {
			if ($this->maxCandidateDepth !== null && $this->maxCandidateDepth < 50) {
				throw new \InvalidArgumentException("Maximum candidate depth must be at least 50, got {$this->maxCandidateDepth}.");
			}

			if ($this->maxBackfillRounds !== null && $this->maxBackfillRounds < 1) {
				throw new \InvalidArgumentException("Maximum backfill rounds must be at least 1, got {$this->maxBackfillRounds}.");
			}

			if ($this->maxEligibilityBatchSize !== null && $this->maxEligibilityBatchSize < 1) {
				throw new \InvalidArgumentException("Maximum eligibility batch size must be at least 1, got {$this->maxEligibilityBatchSize}.");
			}
		}
	}
