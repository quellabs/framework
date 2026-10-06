<?php

	namespace Quellabs\Recommender;

	/** Thresholds that decide when a member or visitor gets fallback items instead of collaborative scores. */
	readonly class ColdStartPolicy {

		/** @var int Minimum genuine ratings before collaborative scoring */
		public int $minHistory;

		/** @var int Minimum ratings for a top-rated fallback item */
		public int $topRatedMinRatings;

		/**
		 * Build a cold-start policy from validated thresholds.
		 * @param MinHistory $minHistory Minimum genuine ratings before collaborative scoring
		 * @param MinRatings $topRatedMinRatings Minimum ratings for a top-rated fallback item
		 */
		public function __construct(MinHistory $minHistory = new MinHistory(1), MinRatings $topRatedMinRatings = new MinRatings(2)) {
			$this->minHistory = $minHistory->value;
			$this->topRatedMinRatings = $topRatedMinRatings->value;
		}
	}
