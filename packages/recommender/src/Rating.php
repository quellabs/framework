<?php

	namespace Quellabs\Recommender;

	/** One rating a member gave a product, as stored. */
	readonly class Rating {

		/** @var int Member who gave the rating */
		public int $memberId;

		/** @var int Rated product ID */
		public int $productId;

		/** @var float Rating in [0.0, 1.0], or the not-interested sentinel */
		public float $rating;

		/** @var string Time the rating was stored, as a MySQL datetime string */
		public string $timestamp;

		/**
		 * Build a rating from its stored fields.
		 * @param int $memberId Member who gave the rating
		 * @param int $productId Rated product ID
		 * @param float $rating Rating in [0.0, 1.0], or the not-interested sentinel
		 * @param string $timestamp Time the rating was stored, as a MySQL datetime string
		 */
		public function __construct(int $memberId, int $productId, float $rating, string $timestamp) {
			$this->memberId = $memberId;
			$this->productId = $productId;
			$this->rating = $rating;
			$this->timestamp = $timestamp;
		}
	}
