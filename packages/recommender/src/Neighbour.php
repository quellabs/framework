<?php

	namespace Quellabs\Recommender;

	/** A member whose ratings resemble the reference member's, with the similarity score. */
	readonly class Neighbour {

		/** @var int Neighbouring member ID */
		public int $memberId;

		/** @var int Similarity score in [0, 100] */
		public int $similarity;

		/**
		 * Store the neighbour's ID and similarity.
		 * @param int $memberId Neighbouring member ID
		 * @param int $similarity Similarity score in [0, 100]
		 */
		public function __construct(int $memberId, int $similarity) {
			$this->memberId = $memberId;
			$this->similarity = $similarity;
		}
	}
