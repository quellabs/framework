<?php
	
	namespace Quellabs\Recommender\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\VisitorContext;
	
	/**
	 * Unit tests for VisitorContext.
	 *
	 * Pure in-memory logic — no database required.
	 */
	class VisitorContextTest extends TestCase {
		
		private RecommendationConfig $config;
		private VisitorContext $visitor;
		
		protected function setUp(): void {
			$this->config  = new RecommendationConfig(category: 1);
			$this->visitor = new VisitorContext($this->config);
		}
		
		// =========================================================================
		// isEmpty
		// =========================================================================
		
		public function testIsEmptyWhenNoRatings(): void {
			$this->assertTrue($this->visitor->isEmpty());
		}
		
		public function testIsNotEmptyAfterSetRating(): void {
			$this->visitor->setRating(1, 0.8);
			$this->assertFalse($this->visitor->isEmpty());
		}
		
		// =========================================================================
		// setRating / getRatings
		// =========================================================================
		
		public function testSetRatingStoresEntry(): void {
			$this->visitor->setRating(10, 0.9);
			$ratings = $this->visitor->getRatings();
			$this->assertCount(1, $ratings);
			$this->assertSame(10, $ratings[0]['product_id']);
			$this->assertEqualsWithDelta(0.9, $ratings[0]['rating'], 0.0001);
			$this->assertSame(1, $ratings[0]['category']);
		}
		
		public function testSetRatingUpdatesExistingEntry(): void {
			$this->visitor->setRating(10, 0.5);
			$this->visitor->setRating(10, 0.9);
			$ratings = $this->visitor->getRatings();
			$this->assertCount(1, $ratings);
			$this->assertEqualsWithDelta(0.9, $ratings[0]['rating'], 0.0001);
		}
		
		public function testSetRatingStoresMultipleProducts(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->setRating(2, 0.5);
			$this->visitor->setRating(3, 0.3);
			$this->assertCount(3, $this->visitor->getRatings());
		}
		
		public function testSetRatingRespectsCategory(): void {
			$this->visitor->setRating(1, 0.8, 1);
			$this->visitor->setRating(1, 0.5, 2);
			$this->assertCount(1, $this->visitor->getRatings(1));
			$this->assertCount(1, $this->visitor->getRatings(2));
		}
		
		public function testSetRatingUsesDefaultCategoryWhenNull(): void {
			$this->visitor->setRating(1, 0.8, null);
			$ratings = $this->visitor->getRatings();
			$this->assertSame(1, $ratings[0]['category']);
		}
		
		// =========================================================================
		// setNotInterested
		// =========================================================================
		
		public function testSetNotInterestedStoresSentinelValue(): void {
			$this->visitor->setNotInterested(5);
			$ratings = $this->visitor->getRatings();
			$this->assertCount(1, $ratings);
			$this->assertEqualsWithDelta($this->config->getNotInterested(), $ratings[0]['rating'], 0.0001);
		}
		
		public function testSetNotInterestedUpdatesExistingRating(): void {
			$this->visitor->setRating(5, 0.8);
			$this->visitor->setNotInterested(5);
			$ratings = $this->visitor->getRatings();
			$this->assertCount(1, $ratings);
			$this->assertEqualsWithDelta($this->config->getNotInterested(), $ratings[0]['rating'], 0.0001);
		}
		
		// =========================================================================
		// removeRating
		// =========================================================================
		
		public function testRemoveRatingDeletesEntry(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->setRating(2, 0.5);
			$this->visitor->removeRating(1);
			$ratings = $this->visitor->getRatings();
			$this->assertCount(1, $ratings);
			$this->assertSame(2, $ratings[0]['product_id']);
		}
		
		public function testRemoveRatingOnNonExistentProductIsNoop(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->removeRating(99);
			$this->assertCount(1, $this->visitor->getRatings());
		}
		
		public function testRemoveRatingRespectsCategory(): void {
			$this->visitor->setRating(1, 0.8, 1);
			$this->visitor->setRating(1, 0.5, 2);
			$this->visitor->removeRating(1, 1);
			$this->assertCount(0, $this->visitor->getRatings(1));
			$this->assertCount(1, $this->visitor->getRatings(2));
		}
		
		public function testRemoveRatingReindexesArray(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->setRating(2, 0.5);
			$this->visitor->setRating(3, 0.3);
			$this->visitor->removeRating(2);
			$ratings = $this->visitor->getRatings();
			$this->assertArrayHasKey(0, $ratings);
			$this->assertArrayHasKey(1, $ratings);
			$this->assertArrayNotHasKey(2, $ratings);
		}
		
		// =========================================================================
		// getRatedProductIds
		// =========================================================================
		
		public function testGetRatedProductIdsReturnsEmptyWhenNoRatings(): void {
			$this->assertSame([], $this->visitor->getRatedProductIds());
		}
		
		public function testGetRatedProductIdsReturnsAllProductIds(): void {
			$this->visitor->setRating(10, 0.8);
			$this->visitor->setRating(20, 0.5);
			$ids = $this->visitor->getRatedProductIds();
			$this->assertEqualsCanonicalizing([10, 20], $ids);
		}
		
		public function testGetRatedProductIdsFiltersToCategory(): void {
			$this->visitor->setRating(1, 0.8, 1);
			$this->visitor->setRating(2, 0.5, 2);
			$this->assertSame([1], $this->visitor->getRatedProductIds(1));
			$this->assertSame([2], $this->visitor->getRatedProductIds(2));
		}
		
		// =========================================================================
		// getRatings category filtering
		// =========================================================================
		
		public function testGetRatingsUsesDefaultCategoryWhenNull(): void {
			$config  = new RecommendationConfig(category: 2);
			$visitor = new VisitorContext($config);
			$visitor->setRating(1, 0.8, 2);
			$visitor->setRating(2, 0.5, 3);
			$this->assertCount(1, $visitor->getRatings(null));
			$this->assertSame(1, $visitor->getRatings(null)[0]['product_id']);
		}
	}