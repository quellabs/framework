<?php

	namespace Quellabs\Recommender;

	/** Thresholds that decide when a member or visitor gets fallback items instead of collaborative scores. */
	readonly class ColdStartPolicy {

		/** @var int Minimum genuine ratings before collaborative scoring */
		public int $minHistory;

		/** @var int Minimum ratings for a top-rated fallback item */
		public int $topRatedMinRatings;

		/**
		 * Build a cold-start policy.
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring, at least 1
		 * @param int $topRatedMinRatings Minimum ratings for a top-rated fallback item, at least 1
		 * @throws \InvalidArgumentException When a threshold is below 1
		 */
		public function __construct(int $minHistory = 1, int $topRatedMinRatings = 2) {
			if ($minHistory < 1) {
				throw new \InvalidArgumentException("Minimum history must be at least 1, got {$minHistory}.");
			}

			if ($topRatedMinRatings < 1) {
				throw new \InvalidArgumentException("Top-rated minimum ratings must be at least 1, got {$topRatedMinRatings}.");
			}

			$this->minHistory = $minHistory;
			$this->topRatedMinRatings = $topRatedMinRatings;
		}
	}
