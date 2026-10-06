<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** Maximum source depth, at least 50. */
	readonly class SourceDepth {

		/** @var int Wrapped value */
		public int $value;

		/**
		 * Build the value object.
		 * @param int $value Maximum source depth, at least 50
		 * @throws \InvalidArgumentException When the value is outside its allowed range
		 */
		public function __construct(int $value) {
			if ($value < 50) {
				throw new \InvalidArgumentException("Maximum candidate depth must be at least 50, got {$value}.");
			}

			$this->value = $value;
		}
	}
