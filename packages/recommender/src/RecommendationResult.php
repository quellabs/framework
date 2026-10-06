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

		/** @var int|null Summed pair support, or null when the source does not count support */
		public ?int $supportCount;
		
		/**
		 * Build a recommendation result from its scored fields.
		 * @param int $productId Recommended product ID
		 * @param float $score Source-specific score
		 * @param RecommendationSource $source Candidate source that produced the score
		 * @param array<int, int> $contributingProductIds Rated products contributing to the score
		 * @param int|null $supportCount Summed pair support, at least 1, or null when the source does not count support
		 * @throws \InvalidArgumentException When the support count is below 1
		 */
		public function __construct(int $productId, float $score, RecommendationSource $source, array $contributingProductIds, ?int $supportCount = null) {
			if ($supportCount !== null && $supportCount < 1) {
				throw new \InvalidArgumentException("Support count must be at least 1, got {$supportCount}.");
			}

			$this->productId = $productId;
			$this->score = $score;
			$this->source = $source;
			$this->contributingProductIds = $contributingProductIds;
			$this->supportCount = $supportCount;
		}
	}
