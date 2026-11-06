<?php
	
	namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ProductId;
	
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
			$ratings = $this->visitor->ratings();
			$this->assertCount(1, $ratings);
			$this->assertSame(10, $ratings[0]->productId);
			$this->assertEqualsWithDelta(0.9, $ratings[0]->rating, 0.0001);
			$this->assertCount(1, $this->visitor->ratings(1));
		}
		
		public function testSetRatingUpdatesExistingEntry(): void {
			$this->visitor->setRating(10, 0.5);
			$this->visitor->setRating(10, 0.9);
			$ratings = $this->visitor->ratings();
			$this->assertCount(1, $ratings);
			$this->assertEqualsWithDelta(0.9, $ratings[0]->rating, 0.0001);
		}
		
		public function testSetRatingStoresMultipleProducts(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->setRating(2, 0.5);
			$this->visitor->setRating(3, 0.3);
			$this->assertCount(3, $this->visitor->ratings());
		}
		
		public function testSetRatingRespectsCategory(): void {
			$this->visitor->setRating(1, 0.8, 1);
			$this->visitor->setRating(1, 0.5, 2);
			$this->assertCount(1, $this->visitor->ratings(1));
			$this->assertCount(1, $this->visitor->ratings(2));
		}
		
		public function testSetRatingUsesDefaultCategoryWhenNull(): void {
			$this->visitor->setRating(1, 0.8, null);
			$this->assertCount(1, $this->visitor->ratings(1));
		}
		
		// =========================================================================
		// setNotInterested
		// =========================================================================
		
		public function testSetNotInterestedStoresSentinelValue(): void {
			$this->visitor->setNotInterested(5);
			$ratings = $this->visitor->ratings();
			$this->assertCount(1, $ratings);
			$this->assertEqualsWithDelta(RecommendationConfig::NOT_INTERESTED, $ratings[0]->rating, 0.0001);
		}
		
		public function testSetNotInterestedUpdatesExistingRating(): void {
			$this->visitor->setRating(5, 0.8);
			$this->visitor->setNotInterested(5);
			$ratings = $this->visitor->ratings();
			$this->assertCount(1, $ratings);
			$this->assertEqualsWithDelta(RecommendationConfig::NOT_INTERESTED, $ratings[0]->rating, 0.0001);
		}
		
		// =========================================================================
		// deleteRating
		// =========================================================================
		
		public function testRemoveRatingDeletesEntry(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->setRating(2, 0.5);
			$this->visitor->deleteRating(1);
			$ratings = $this->visitor->ratings();
			$this->assertCount(1, $ratings);
			$this->assertSame(2, $ratings[0]->productId);
		}
		
		public function testRemoveRatingOnNonExistentProductIsNoop(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->deleteRating(99);
			$this->assertCount(1, $this->visitor->ratings());
		}
		
		public function testRemoveRatingRespectsCategory(): void {
			$this->visitor->setRating(1, 0.8, 1);
			$this->visitor->setRating(1, 0.5, 2);
			$this->visitor->deleteRating(1, 1);
			$this->assertCount(0, $this->visitor->ratings(1));
			$this->assertCount(1, $this->visitor->ratings(2));
		}
		
		public function testRemoveRatingReindexesArray(): void {
			$this->visitor->setRating(1, 0.8);
			$this->visitor->setRating(2, 0.5);
			$this->visitor->setRating(3, 0.3);
			$this->visitor->deleteRating(2);
			$ratings = $this->visitor->ratings();
			$this->assertArrayHasKey(0, $ratings);
			$this->assertArrayHasKey(1, $ratings);
			$this->assertArrayNotHasKey(2, $ratings);
		}
		
		// =========================================================================
		// getRatedProductIds
		// =========================================================================
		
		public function testGetRatedProductIdsReturnsEmptyWhenNoRatings(): void {
			$this->assertSame([], $this->visitor->ratedProductIds());
		}
		
		public function testGetRatedProductIdsReturnsAllProductIds(): void {
			$this->visitor->setRating(10, 0.8);
			$this->visitor->setRating(20, 0.5);
			$ids = $this->visitor->ratedProductIds();
			$this->assertEqualsCanonicalizing([10, 20], $ids);
		}
		
		public function testGetRatedProductIdsFiltersToCategory(): void {
			$this->visitor->setRating(1, 0.8, 1);
			$this->visitor->setRating(2, 0.5, 2);
			$this->assertSame([1], $this->visitor->ratedProductIds(1));
			$this->assertSame([2], $this->visitor->ratedProductIds(2));
		}
		
		// =========================================================================
		// getRatings category filtering
		// =========================================================================
		
		public function testGetRatingsUsesDefaultCategoryWhenNull(): void {
			$config  = new RecommendationConfig(category: 2);
			$visitor = new VisitorContext($config);
			$visitor->setRating(1, 0.8, 2);
			$visitor->setRating(2, 0.5, 3);
			$this->assertCount(1, $visitor->ratings(null));
			$this->assertSame(1, $visitor->ratings(null)[0]->productId);
		}

		// =========================================================================
		// recordPurchase / recordClick
		// =========================================================================

		public function testRecordPurchaseStoresMaximumRating(): void {
			$this->visitor->recordPurchase(10);
			$this->assertEqualsWithDelta(1.0, $this->visitor->ratings()[0]->rating, 0.0001);
		}

		public function testRecordClickStartsAtFirstClickRating(): void {
			$this->visitor->recordClick(10);
			$this->assertEqualsWithDelta(0.7, $this->visitor->ratings()[0]->rating, 0.0001);
		}

		public function testRecordClickRaisesExistingRating(): void {
			$this->visitor->recordClick(10);
			$this->visitor->recordClick(10);
			$this->assertCount(1, $this->visitor->ratings());
			$this->assertEqualsWithDelta(0.71, $this->visitor->ratings()[0]->rating, 0.0001);
		}

		public function testRecordClickLeavesPurchaseUnchanged(): void {
			$this->visitor->recordPurchase(10);
			$this->visitor->recordClick(10);
			$this->assertEqualsWithDelta(1.0, $this->visitor->ratings()[0]->rating, 0.0001);
		}
	}