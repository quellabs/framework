<?php

	namespace Quellabs\Recommender;

	/** Minimum genuine ratings before collaborative scoring, at least 1. */
	readonly class MinHistory {

		/** @var int Wrapped value */
		public int $value;

		/**
		 * Build the value object.
		 * @param int $value Minimum genuine ratings before collaborative scoring, at least 1
		 * @throws \InvalidArgumentException When the value is outside its allowed range
		 */
		public function __construct(int $value) {
			if ($value < 1) {
				throw new \InvalidArgumentException("Minimum history must be at least 1, got {$value}.");
			}

			$this->value = $value;
		}
	}
