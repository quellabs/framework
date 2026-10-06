<?php

	namespace Quellabs\Recommender;

	/** One rating an anonymous visitor gave a product in the current session. */
	readonly class VisitorRating {

		/** @var int Rated product ID */
		public int $productId;

		/** @var float Rating in [0.0, 1.0], or the not-interested sentinel */
		public float $rating;

		/**
		 * Build a visitor rating from its fields.
		 * @param int $productId Rated product ID
		 * @param float $rating Rating in [0.0, 1.0], or the not-interested sentinel
		 */
		public function __construct(int $productId, float $rating) {
			$this->productId = $productId;
			$this->rating = $rating;
		}
	}
