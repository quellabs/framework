<?php
	
	namespace Quellabs\Recommender\Evaluation;
	
	use DateTimeImmutable;
	
	/** Descriptive rates for actually displayed items. */
	readonly class EvaluationSummary {
		
		/** @var int Displayed item count */
		public int $impressions;
		
		/** @var int Items clicked at least once */
		public int $clickedItems;
		
		/** @var int Items purchased at least once */
		public int $purchasedItems;
		
		/** @var DateTimeImmutable Outcome cutoff */
		public DateTimeImmutable $asOf;
		
		/** @var AttributionWindows Caller-selected attribution periods */
		public AttributionWindows $windows;
		
		/**
		 * Build a summary from its counts and report settings.
		 * @param int $impressions Displayed item count
		 * @param int $clickedItems Items clicked at least once
		 * @param int $purchasedItems Items purchased at least once
		 * @param DateTimeImmutable $asOf Outcome cutoff
		 * @param AttributionWindows $windows Caller-selected attribution periods
		 */
		public function __construct(int $impressions, int $clickedItems, int $purchasedItems, DateTimeImmutable $asOf, AttributionWindows $windows) {
			$this->impressions = $impressions;
			$this->clickedItems = $clickedItems;
			$this->purchasedItems = $purchasedItems;
			$this->asOf = $asOf;
			$this->windows = $windows;
		}
		
		/**
		 * Return the share of displayed items that were clicked.
		 * @return float Displayed-item click rate
		 */
		public function clickThroughRate(): float {
			return $this->impressions === 0 ? 0.0 : $this->clickedItems / $this->impressions;
		}
		
		/**
		 * Return the share of displayed items that were purchased.
		 * @return float Displayed-item purchase rate
		 */
		public function purchaseRate(): float {
			return $this->impressions === 0 ? 0.0 : $this->purchasedItems / $this->impressions;
		}
	}
