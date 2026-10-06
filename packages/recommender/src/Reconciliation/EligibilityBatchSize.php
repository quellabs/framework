<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** Maximum IDs per eligibility provider call, at least 1. */
	readonly class EligibilityBatchSize {

		/** @var int Wrapped value */
		public int $value;

		/**
		 * Build the value object.
		 * @param int $value Maximum IDs per eligibility provider call, at least 1
		 * @throws \InvalidArgumentException When the value is outside its allowed range
		 */
		public function __construct(int $value) {
			if ($value < 1) {
				throw new \InvalidArgumentException("Maximum eligibility batch size must be at least 1, got {$value}.");
			}

			$this->value = $value;
		}
	}
