<?php
	
	namespace Quellabs\Recommender;
	
	/** Caller-selected outcome attribution periods. */
	readonly class AttributionWindows {
		
		/**
		 *  @param int $clickSeconds Positive click period
		 *  @param int $purchaseSeconds Positive purchase period
		 */
		public function __construct(public int $clickSeconds, public int $purchaseSeconds) {
			if ($clickSeconds < 1 || $purchaseSeconds < 1) {
				throw new \InvalidArgumentException('Attribution windows must be positive.');
			}
		}
	}