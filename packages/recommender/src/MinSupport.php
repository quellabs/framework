<?php

	namespace Quellabs\Recommender;

	/** Minimum summed pair support for a Slope One diff or prediction, at least 1. */
	readonly class MinSupport {

		/** @var int Minimum summed pair support */
		public int $value;

		/**
		 * Build a support threshold.
		 * @param int $value Minimum summed pair support, at least 1
		 * @throws \InvalidArgumentException When the value is below 1
		 */
		public function __construct(int $value = 1) {
			if ($value < 1) {
				throw new \InvalidArgumentException("Minimum support must be at least 1, got {$value}.");
			}

			$this->value = $value;
		}
	}
