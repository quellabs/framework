<?php
	
	namespace Quellabs\Recommender;
	
	/** A recommendation with a score whose meaning is defined by its source. */
	readonly class RecommendationResult {
		
		/** @var int Recommended product ID */
		public int $productId;
		
		/** @var float Source-specific score */
		public float $score;
		
		/** @var RecommendationSource Candidate source that produced the score */
		public RecommendationSource $source;
		
		/** @var array<int, int> Rated products contributing to the score */
		public array $contributingProductIds;
		
		/**
		 * Build a recommendation result from its scored fields.
		 * @param int $productId Recommended product ID
		 * @param float $score Source-specific score
		 * @param RecommendationSource $source Candidate source that produced the score
		 * @param array<int, int> $contributingProductIds Rated products contributing to the score
		 */
		public function __construct(
			int    $productId,
			float  $score,
			RecommendationSource $source,
			array  $contributingProductIds
		) {
			$this->productId = $productId;
			$this->score = $score;
			$this->source = $source;
			$this->contributingProductIds = $contributingProductIds;
		}
	}
