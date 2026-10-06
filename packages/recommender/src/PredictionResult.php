<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	/** A Slope One rating with summed directed-pair support. */
	readonly class PredictionResult {
		
		/** @var int Product ID */
		public int $productId;
		
		/** @var float Clamped predicted rating */
		public float $predictedRating;
		
		/** @var int Sum of contributing pair counts */
		public int $supportCount;
		
		/**
		 * Build a prediction, rejecting values outside their allowed ranges.
		 * @param int $productId Product ID, an unsigned 32-bit integer
		 * @param float $predictedRating Clamped predicted rating in [0, 1]
		 * @param int $supportCount Sum of contributing pair counts, at least 1
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(int $productId, float $predictedRating, int $supportCount) {
			if ($productId < 0 || $productId > Identifier::MAX) {
				throw new \InvalidArgumentException("Product ID must be an unsigned 32-bit integer, got {$productId}.");
			}
			
			if (!is_finite($predictedRating) || $predictedRating < 0 || $predictedRating > 1) {
				throw new \InvalidArgumentException("Predicted rating must be a finite value in [0, 1], got {$predictedRating}.");
			}
			
			if ($supportCount < 1) {
				throw new \InvalidArgumentException("Support count must be at least 1, got {$supportCount}.");
			}
			
			$this->productId = $productId;
			$this->predictedRating = $predictedRating;
			$this->supportCount = $supportCount;
		}
	}
