<?php

	namespace Quellabs\Recommender;

	/** Minimum number of ratings a product needs to qualify, at least 1. */
	readonly class MinRatings {

		/** @var int Minimum number of ratings */
		public int $value;

		/**
		 * Build a rating-count threshold.
		 * @param int $value Minimum number of ratings, at least 1
		 * @throws \InvalidArgumentException When the value is below 1
		 */
		public function __construct(int $value = 1) {
			if ($value < 1) {
				throw new \InvalidArgumentException("Minimum ratings must be at least 1, got {$value}.");
			}

			$this->value = $value;
		}
	}
