<?php
	
	namespace Quellabs\Recommender;
	
	use DateTimeImmutable;
	
	/** Descriptive rates for actually displayed items. */
	readonly class EvaluationSummary {
		/** @param int $impressions Displayed item count
		 * @param int $clickedItems Items clicked at least once
		 * @param int $purchasedItems Items purchased at least once
		 * @param DateTimeImmutable $asOf Outcome cutoff
		 * @param AttributionWindows $windows Caller-selected attribution periods
		 */
		public function __construct(
			public int $impressions,
			public int $clickedItems,
			public int $purchasedItems,
			public DateTimeImmutable $asOf,
			public AttributionWindows $windows,
		) {
		}
		
		/** @return float Displayed-item click rate. */
		public function clickThroughRate(): float {
			return $this->impressions === 0 ? 0.0 : $this->clickedItems / $this->impressions;
		}
		
		/** @return float Displayed-item purchase rate. */
		public function purchaseRate(): float {
			return $this->impressions === 0 ? 0.0 : $this->purchasedItems / $this->impressions;
		}
	}
