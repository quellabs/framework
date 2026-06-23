<?php
	
	namespace Quellabs\Recommender\Tests;
	
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\ItemRecommender;
	use Quellabs\Recommender\VisitorContext;
	
	/**
	 * Integration tests for ItemRecommender.
	 * Requires a live MySQL database — see tests/bootstrap.php.
	 */
	class ItemRecommenderTest extends IntegrationTestCase {
		
		private ItemRecommender $recommender;
		
		protected function setUp(): void {
			parent::setUp();
			$this->recommender = new ItemRecommender($this->connection, $this->config);
		}
		
		// =========================================================================
		// getLinkedItems
		// =========================================================================
		
		public function testGetLinkedItemsReturnsEmptyWhenNoLinks(): void {
			$this->assertSame([], $this->recommender->getLinkedItems(1));
		}
		
		public function testGetLinkedItemsReturnsLinkedProducts(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$result = $this->recommender->getLinkedItems(1);
			$this->assertEqualsCanonicalizing([2, 3], $result);
		}
		
		public function testGetLinkedItemsOrderedByCountDescending(): void {
			$this->insertLink(1, 2, 3);
			$this->insertLink(1, 3, 10);
			$this->insertLink(1, 4, 5);
			$result = $this->recommender->getLinkedItems(1);
			$this->assertSame([3, 4, 2], $result);
		}
		
		public function testGetLinkedItemsRespectsLimit(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$this->insertLink(1, 4, 1);
			$result = $this->recommender->getLinkedItems(1, limit: 2);
			$this->assertCount(2, $result);
		}
		
		public function testGetLinkedItemsRespectsFilter(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$result = $this->recommender->getLinkedItems(1, filter: [2]);
			$this->assertSame([2], $result);
		}
		
		// =========================================================================
		// memberGetRecommendedItems (links strategy)
		// =========================================================================
		
		public function testMemberGetRecommendedItemsReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.8);
			$this->assertSame([], $this->recommender->memberGetRecommendedItems(1));
		}
		
		public function testMemberGetRecommendedItemsReturnsUnratedLinkedItems(): void {
			// Member rated product 10; product 10 is linked to 20 and 30
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$result = $this->recommender->memberGetRecommendedItems(1);
			$this->assertEqualsCanonicalizing([20, 30], $result);
		}
		
		public function testMemberGetRecommendedItemsExcludesAlreadyRatedItems(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertRating(1, 20, 0.5);
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$result = $this->recommender->memberGetRecommendedItems(1);
			$this->assertNotContains(20, $result);
			$this->assertContains(30, $result);
		}
		
		public function testMemberGetRecommendedItemsRespectsLimit(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 10);
			$this->insertLink(10, 30, 8);
			$this->insertLink(10, 40, 5);
			$result = $this->recommender->memberGetRecommendedItems(1, limit: 2);
			$this->assertCount(2, $result);
		}
		
		// =========================================================================
		// memberGetReasons
		// =========================================================================
		
		public function testMemberGetReasonsReturnsRatedLinkedProducts(): void {
			// Member rated 10 and 20; product 30 is linked to both
			$this->insertRating(1, 10, 0.9);
			$this->insertRating(1, 20, 0.8);
			$this->insertLink(30, 10, 5);
			$this->insertLink(30, 20, 3);
			$result = $this->recommender->memberGetReasons(1, 30);
			$this->assertEqualsCanonicalizing([10, 20], $result);
		}
		
		public function testMemberGetReasonsReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.9);
			$this->assertSame([], $this->recommender->memberGetReasons(1, 99));
		}
		
		// =========================================================================
		// getSlopeItems
		// =========================================================================
		
		public function testGetSlopeItemsReturnsItemsWithDiff(): void {
			$this->insertLink(1, 2, 3, 0.6);
			$this->insertLink(1, 3, 2, 0.2);
			$result = $this->recommender->getSlopeItems(1);
			$this->assertCount(2, $result);
			$this->assertArrayHasKey('product_id', $result[0]);
			$this->assertArrayHasKey('diff', $result[0]);
		}
		
		public function testGetSlopeItemsOrderedByAvgDiffDescending(): void {
			// item 2: diff=0.6/3=0.2, item 3: diff=0.9/2=0.45
			$this->insertLink(1, 2, 3, 0.6);
			$this->insertLink(1, 3, 2, 0.9);
			$result = $this->recommender->getSlopeItems(1);
			$this->assertSame(3, $result[0]['product_id']);
			$this->assertSame(2, $result[1]['product_id']);
		}
		
		public function testGetSlopeItemsRespectsMinLinks(): void {
			$this->insertLink(1, 2, 1, 0.5);
			$this->insertLink(1, 3, 5, 0.5);
			$result = $this->recommender->getSlopeItems(1, minLinks: 3);
			$this->assertCount(1, $result);
			$this->assertSame(3, $result[0]['product_id']);
		}
		
		// =========================================================================
		// memberPredict
		// =========================================================================
		
		public function testMemberPredictReturnsNullWithNoData(): void {
			$this->assertNull($this->recommender->memberPredict(1, 99));
		}
		
		public function testMemberPredictReturnsPredictedRating(): void {
			// Member rated product 2 at 0.8; link 1->2 has cnt=1, diff_slope=-0.1
			// predicted = (0.8 * 1 - (-0.1)) / 1 = 0.9
			$this->insertRating(1, 2, 0.8);
			$this->insertLink(1, 2, 1, -0.1);
			$result = $this->recommender->memberPredict(1, 1);
			$this->assertNotNull($result);
			$this->assertEqualsWithDelta(0.9, $result, 0.0001);
		}
		
		public function testMemberPredictClampsToOne(): void {
			$this->insertRating(1, 2, 1.0);
			$this->insertLink(1, 2, 1, 0.5);
			$result = $this->recommender->memberPredict(1, 1);
			$this->assertLessThanOrEqual(1.0, $result);
		}
		
		public function testMemberPredictClampsToZero(): void {
			$this->insertRating(1, 2, 0.0);
			$this->insertLink(1, 2, 1, -0.5);
			$result = $this->recommender->memberPredict(1, 1);
			$this->assertGreaterThanOrEqual(0.0, $result);
		}
		
		// =========================================================================
		// memberPredictAll
		// =========================================================================
		
		public function testMemberPredictAllReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.8);
			$this->assertSame([], $this->recommender->memberPredictAll(1));
		}
		
		public function testMemberPredictAllExcludesAlreadyRatedItems(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 20, 0.5);
			$this->insertLink(10, 20, 2, 0.1);
			$this->insertLink(10, 30, 2, 0.2);
			$result     = $this->recommender->memberPredictAll(1);
			$productIds = array_column($result, 'product_id');
			$this->assertNotContains(10, $productIds);
			$this->assertNotContains(20, $productIds);
		}
		
		public function testMemberPredictAllIsSortedDescending(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertLink(10, 20, 2, 0.1);
			$this->insertLink(10, 30, 2, -0.1);
			$result  = $this->recommender->memberPredictAll(1);
			$ratings = array_column($result, 'rating');
			
			for ($i = 1; $i < count($ratings); $i++) {
				$this->assertGreaterThanOrEqual($ratings[$i], $ratings[$i - 1]);
			}
		}
		
		// =========================================================================
		// Visitor methods
		// =========================================================================
		
		public function testVisitorGetRecommendedItemsReturnsEmptyForEmptyContext(): void {
			$visitor = new VisitorContext($this->config);
			$this->assertSame([], $this->recommender->visitorGetRecommendedItems($visitor));
		}
		
		public function testVisitorGetRecommendedItemsReturnsLinkedItems(): void {
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$result = $this->recommender->visitorGetRecommendedItems($visitor);
			$this->assertEqualsCanonicalizing([20, 30], $result);
		}
		
		public function testVisitorGetRecommendedItemsExcludesAlreadyRatedProducts(): void {
			$this->insertLink(10, 20, 5);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$visitor->setRating(20, 0.5);
			$result = $this->recommender->visitorGetRecommendedItems($visitor);
			$this->assertNotContains(20, $result);
		}
		
		public function testVisitorPredictReturnsNullForEmptyContext(): void {
			$visitor = new VisitorContext($this->config);
			$this->assertNull($this->recommender->visitorPredict($visitor, 1));
		}
		
		public function testVisitorPredictReturnsPredictedRating(): void {
			$this->insertLink(1, 2, 1, -0.1);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(2, 0.8);
			$result = $this->recommender->visitorPredict($visitor, 1);
			$this->assertNotNull($result);
			$this->assertEqualsWithDelta(0.9, $result, 0.0001);
		}
		
		// =========================================================================
		// Category isolation
		// =========================================================================
		
		public function testGetLinkedItemsIsolatedByCategory(): void {
			$this->insertLink(1, 2, 5, 0.0, 1);
			$this->insertLink(1, 3, 5, 0.0, 2);
			$this->assertSame([2], $this->recommender->getLinkedItems(1, category: 1));
			$this->assertSame([3], $this->recommender->getLinkedItems(1, category: 2));
		}
	}