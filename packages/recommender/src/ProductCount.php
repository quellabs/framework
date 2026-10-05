<?php

	namespace Quellabs\Recommender;

	/** A product with the number of genuine ratings it has received. */
	readonly class ProductCount {

		/** @var int Product ID */
		public int $productId;

		/** @var int Number of genuine ratings */
		public int $numRatings;

		/**
		 * Store the product's ID and rating count.
		 * @param int $productId Product ID
		 * @param int $numRatings Number of genuine ratings
		 */
		public function __construct(int $productId, int $numRatings) {
			$this->productId = $productId;
			$this->numRatings = $numRatings;
		}
	}
