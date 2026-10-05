<?php
	
	namespace Quellabs\Recommender;
	
	/** A recommendation with a score whose meaning is defined by its strategy. */
	readonly class RecommendationResult {
		
		/** @var int Recommended product ID */
		public int $itemId;
		
		/** @var float Strategy-specific score */
		public float $score;
		
		/** @var string Strategy name, such as item_links, slope_one or top_rated */
		public string $strategy;
		
		/** @var array<int, int> Rated items contributing to the score */
		public array $contributingItemIds;
		
		/**
		 * Build a recommendation result from its scored fields.
		 * @param int $itemId Recommended product ID
		 * @param float $score Strategy-specific score
		 * @param string $strategy Strategy name, such as item_links, slope_one or top_rated
		 * @param array<int, int> $contributingItemIds Rated items contributing to the score
		 */
		public function __construct(
			int    $itemId,
			float  $score,
			string $strategy,
			array  $contributingItemIds
		) {
			$this->itemId = $itemId;
			$this->score = $score;
			$this->strategy = $strategy;
			$this->contributingItemIds = $contributingItemIds;
		}
	}
