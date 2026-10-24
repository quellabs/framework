<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Identifier;
	use Quellabs\Recommender\Internal\ImplicitRating;
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
		 * @param int $product The product ID
		 * @param float $rating Rating in [0.0, 1.0], or the not-interested sentinel
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 * @throws \InvalidArgumentException When the product ID or rating is invalid
		 */
		public function setRating(int $product, float $rating, ?int $category = null): void {
			Identifier::assertId($product, 'Product ID');
			
			if (!RatingRule::isValid($rating, RecommendationConfig::NOT_INTERESTED)) {
				throw new \InvalidArgumentException("Rating must be in [0.0, 1.0] or the not-interested sentinel, got {$rating}.");
			}
			
			$resolvedCategory = $this->config->resolveCategory($category);
			
			foreach ($this->ratings as $index => $entry) {
				if ($entry['product_id'] === $product && $entry['category'] === $resolvedCategory) {
					$this->ratings[$index]['rating'] = $rating;
					return;
				}
			}
			
			$this->ratings[] = [
				'product_id' => $product,
				'rating'     => $rating,
				'category'   => $resolvedCategory,
			];
		}
		
		/**
		 * Mark a product as not interested for the given category.
		 * @param int $product The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function setNotInterested(int $product, ?int $category = null): void {
			$this->setRating($product, RecommendationConfig::NOT_INTERESTED, $category);
		}
		
		/**
		 * Record a purchase as a rating of 1.0 for a product in the given category.
		 * @param int $product The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function recordPurchase(int $product, ?int $category = null): void {
			$this->setRating($product, ImplicitRating::PURCHASE, $category);
		}
		
		/**
		 * Record a click as a rating of 0.7, or raise an existing genuine rating by 0.01 up to 1.0.
		 * @param int $product The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function recordClick(int $product, ?int $category = null): void {
			Identifier::assertId($product, 'Product ID');
			$resolvedCategory = $this->config->resolveCategory($category);
			$existing = $this->genuineRating($product, $resolvedCategory);
			
			if ($existing === null || $existing < ImplicitRating::PURCHASE) {
				$this->setRating($product, ImplicitRating::afterClick($existing), $resolvedCategory);
			}
		}
		
		/**
		 * Delete a rating for a product in the given category.
		 * @param int $product The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function deleteRating(int $product, ?int $category = null): void {
			Identifier::assertId($product, 'Product ID');
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$this->ratings = array_values(
				array_filter(
					$this->ratings,
					fn($entry) => !($entry['product_id'] === $product && $entry['category'] === $resolvedCategory)
				)
			);
		}
		
		/**
		 * Return all ratings for the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return array<int, VisitorRating>
		 */
		public function ratings(?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			$ratings = [];
			
			foreach ($this->ratings as $entry) {
				if ($entry['category'] === $resolvedCategory) {
					$ratings[] = new VisitorRating($entry['product_id'], $entry['rating']);
				}
			}
			
			return $ratings;
		}
		
		/**
		 * Return the genuine rating stored for a product in the given category, or null when there is none.
		 * @param int $productId The product ID
		 * @param int $category Already-resolved category
		 * @return float|null
		 */
		private function genuineRating(int $productId, int $category): ?float {
			foreach ($this->ratings as $entry) {
				if ($entry['product_id'] === $productId && $entry['category'] === $category && $entry['rating'] >= 0.0) {
					return $entry['rating'];
				}
			}
			
			return null;
		}
		
		/**
		 * Return all rated product IDs for the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return array<int, int>
		 */
		public function ratedProductIds(?int $category = null): array {
			return array_map(fn(VisitorRating $rating) => $rating->productId, $this->ratings($category));
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
