<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** Minimum neighbour similarity, from 1 to 100. */
	readonly class MinSimilarity {

		/** @var int Wrapped value */
		public int $value;

		/**
		 * Build the value object.
		 * @param int $value Minimum neighbour similarity, from 1 to 100
		 * @throws \InvalidArgumentException When the value is outside its allowed range
		 */
		public function __construct(int $value) {
			if ($value < 1 || $value > 100) {
				throw new \InvalidArgumentException("Minimum neighbour similarity must be between 1 and 100, got {$value}.");
			}

			$this->value = $value;
		}
	}
