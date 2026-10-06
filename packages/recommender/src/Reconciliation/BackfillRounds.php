<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** Maximum deeper-query rounds, at least 1. */
	readonly class BackfillRounds {

		/** @var int Wrapped value */
		public int $value;

		/**
		 * Build the value object.
		 * @param int $value Maximum deeper-query rounds, at least 1
		 * @throws \InvalidArgumentException When the value is outside its allowed range
		 */
		public function __construct(int $value) {
			if ($value < 1) {
				throw new \InvalidArgumentException("Maximum backfill rounds must be at least 1, got {$value}.");
			}

			$this->value = $value;
		}
	}
