<?php

	namespace Quellabs\Recommender;

	/** A product with its average genuine rating. */
	readonly class ProductAverage {

		/** @var int Product ID */
		public int $productId;

		/** @var float Average genuine rating in [0.0, 1.0] */
		public float $averageRating;

		/**
		 * Store the product's ID and average rating.
		 * @param int $productId Product ID
		 * @param float $averageRating Average genuine rating in [0.0, 1.0]
		 */
		public function __construct(int $productId, float $averageRating) {
			$this->productId = $productId;
			$this->averageRating = $averageRating;
		}
	}
