<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\RatingRule;
	
	/**
	 * Holds the in-memory rating state for an anonymous visitor (no member_id).
	 *
	 * The caller persists and restores this object across requests, for example
	 * through session serialization. Recommender classes receive it as an argument.
	 *
	 * @phpstan-type RatingEntry array{product_id: int, rating: float, category: int}
	 * @phpstan-type RatingList array<int, RatingEntry>
	 */
	class VisitorContext {
		
		/** @var RatingList Ratings held for this visitor */
		private array $ratings = [];
		
		/** @var RecommendationConfig Recommendation settings used to resolve categories */
		private readonly RecommendationConfig $config;
		
		/**
		 * Build an empty visitor context.
		 * @param RecommendationConfig $config The recommendation configuration
		 */
		public function __construct(RecommendationConfig $config) {
			$this->config = $config;
		}
		
		/**
		 * Record or update a rating for a product in the given category.
		 * @param int $productId The product ID
		 * @param float $rating Rating in [0.0, 1.0], or the not-interested sentinel
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 * @throws \InvalidArgumentException When the product ID or rating is invalid
		 */
		public function setRating(int $productId, float $rating, ?int $category = null): void {
			if ($productId < 0) {
				throw new \InvalidArgumentException("Product ID must not be negative, got {$productId}.");
			}
			
			if (!RatingRule::isValid($rating, $this->config->getNotInterested())) {
				throw new \InvalidArgumentException("Rating must be in [0.0, 1.0] or the not-interested sentinel, got {$rating}.");
			}
			
			$resolvedCategory = $this->config->resolveCategory($category);
			
			foreach ($this->ratings as $index => $entry) {
				if ($entry['product_id'] === $productId && $entry['category'] === $resolvedCategory) {
					$this->ratings[$index]['rating'] = $rating;
					return;
				}
			}
			
			$this->ratings[] = [
				'product_id' => $productId,
				'rating'     => $rating,
				'category'   => $resolvedCategory,
			];
		}
		
		/**
		 * Mark a product as not interested for the given category.
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 */
		public function setNotInterested(int $productId, ?int $category = null): void {
			$this->setRating($productId, $this->config->getNotInterested(), $category);
		}
		
		/**
		 * Remove a rating for a product in the given category.
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 */
		public function removeRating(int $productId, ?int $category = null): void {
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$this->ratings = array_values(
				array_filter(
					$this->ratings,
					fn($entry) => !($entry['product_id'] === $productId && $entry['category'] === $resolvedCategory)
				)
			);
		}
		
		/**
		 * Return all ratings for the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return RatingList
		 */
		public function ratings(?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			
			return array_values(
				array_filter($this->ratings, fn($entry) => $entry['category'] === $resolvedCategory)
			);
		}
		
		/**
		 * Return all rated product IDs for the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return array<int, int>
		 */
		public function ratedProductIds(?int $category = null): array {
			return array_column($this->ratings($category), 'product_id');
		}
		
		/**
		 * Check whether the visitor has no ratings in the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return bool
		 */
		public function isEmpty(?int $category = null): bool {
			return empty($this->ratings($category));
		}
	}
