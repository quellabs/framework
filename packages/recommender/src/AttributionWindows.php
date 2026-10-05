<?php

namespace Quellabs\Recommender;

/** Caller-selected outcome attribution periods. */
readonly class AttributionWindows {
	
	/** @var int Click attribution period in seconds */
	public int $clickSeconds;
	
	/** @var int Purchase attribution period in seconds */
	public int $purchaseSeconds;
	
	/**
	 * Build the attribution periods, both of which must be positive.
	 * @param int $clickSeconds Positive click period in seconds
	 * @param int $purchaseSeconds Positive purchase period in seconds
	 * @throws \InvalidArgumentException When either period is not positive
	 */
	public function __construct(int $clickSeconds, int $purchaseSeconds) {
		if ($clickSeconds < 1) {
			throw new \InvalidArgumentException("Click attribution period must be positive, got {$clickSeconds}.");
		}
		
		if ($purchaseSeconds < 1) {
			throw new \InvalidArgumentException("Purchase attribution period must be positive, got {$purchaseSeconds}.");
		}
		
		$this->clickSeconds = $clickSeconds;
		$this->purchaseSeconds = $purchaseSeconds;
	}
}
