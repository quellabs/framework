<?php
	
	namespace Quellabs\Recommender;
	
	/** A recommendation with a score whose meaning is defined by its strategy. */
	readonly class RecommendationResult {
		
		/** @var int Recommended product ID */
		public int $itemId;
		
		/** @var float Strategy-specific score */
		public float $score;
		
		/** @var RecommendationSource Candidate source that produced the score */
		public RecommendationSource $strategy;
		
		/** @var array<int, int> Rated items contributing to the score */
		public array $contributingItemIds;
		
		/**
		 * Build a recommendation result from its scored fields.
		 * @param int $itemId Recommended product ID
		 * @param float $score Strategy-specific score
		 * @param RecommendationSource $strategy Candidate source that produced the score
		 * @param array<int, int> $contributingItemIds Rated items contributing to the score
		 */
		public function __construct(
			int    $itemId,
			float  $score,
			RecommendationSource $strategy,
			array  $contributingItemIds
		) {
			$this->itemId = $itemId;
			$this->score = $score;
			$this->strategy = $strategy;
			$this->contributingItemIds = $contributingItemIds;
		}
	}
