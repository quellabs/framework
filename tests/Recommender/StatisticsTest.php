<?php
	
	namespace Quellabs\Recommender\Tests;
	
	use Quellabs\Recommender\Statistics;
	
	/**
	 * Integration tests for Statistics.
	 * Requires a live MySQL database — see tests/bootstrap.php.
	 */
	class StatisticsTest extends IntegrationTestCase {
		
		private Statistics $stats;
		
		protected function setUp(): void {
			parent::setUp();
			$this->stats = new Statistics($this->connection, $this->config);
		}
		
		// =========================================================================
		// numMembers
		// =========================================================================
		
		public function testNumMembersReturnsZeroWhenEmpty(): void {
			$this->assertSame(0, $this->stats->numMembers());
		}
		
		public function testNumMembersCountsDistinctMembers(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 11, 0.5);
			$this->insertRating(2, 10, 0.7);
			$this->assertSame(2, $this->stats->numMembers());
		}
		
		// =========================================================================
		// members
		// =========================================================================
		
		public function testMembersReturnsEmptyArrayWhenNoRatings(): void {
			$this->assertSame([], $this->stats->members());
		}
		
		public function testMembersReturnsDistinctMemberIds(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 11, 0.5);
			$this->insertRating(2, 10, 0.7);
			$this->assertEqualsCanonicalizing([1, 2], $this->stats->members());
		}
		
		// =========================================================================
		// numProducts
		// =========================================================================
		
		public function testNumProductsReturnsZeroWhenEmpty(): void {
			$this->assertSame(0, $this->stats->numProducts());
		}
		
		public function testNumProductsCountsDistinctProducts(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(2, 10, 0.5);
			$this->insertRating(1, 11, 0.7);
			$this->assertSame(2, $this->stats->numProducts());
		}
		
		public function testNumProductsExcludesNotInterestedRatings(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 11, $this->config->getNotInterested());
			$this->assertSame(1, $this->stats->numProducts());
		}
		
		// =========================================================================
		// numRatings
		// =========================================================================
		
		public function testNumRatingsReturnsZeroWhenEmpty(): void {
			$this->assertSame(0, $this->stats->numRatings());
		}
		
		public function testNumRatingsCountsAllGenuineRatings(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 11, 0.5);
			$this->insertRating(2, 10, 0.7);
			$this->assertSame(3, $this->stats->numRatings());
		}
		
		// =========================================================================
		// mostRatedProducts
		// =========================================================================
		
		public function testMostRatedProductsReturnsEmptyWhenNoRatings(): void {
			$this->assertSame([], $this->stats->mostRatedProducts());
		}
		
		public function testMostRatedProductsOrderedDescending(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(2, 10, 0.5);
			$this->insertRating(3, 10, 0.7);
			$this->insertRating(1, 11, 0.6);
			$result = $this->stats->mostRatedProducts();
			$this->assertSame(10, (int)$result[0]['product_id']);
			$this->assertSame(3, (int)$result[0]['num_ratings']);
		}
		
		public function testMostRatedProductsRespectsLimit(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 11, 0.5);
			$this->insertRating(1, 12, 0.3);
			$result = $this->stats->mostRatedProducts(limit: 2);
			$this->assertCount(2, $result);
		}
		
		// =========================================================================
		// topRatedProducts
		// =========================================================================
		
		public function testTopRatedProductsReturnsEmptyWhenNoRatings(): void {
			$this->assertSame([], $this->stats->topRatedProducts());
		}
		
		public function testTopRatedProductsOrderedByAverageDescending(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertRating(2, 10, 0.7);
			$this->insertRating(1, 11, 0.3);
			$this->insertRating(2, 11, 0.1);
			$result = $this->stats->topRatedProducts();
			$this->assertSame(10, (int)$result[0]['product_id']);
			$this->assertGreaterThan($result[1]['avg_rating'], $result[0]['avg_rating']);
		}
		
		public function testTopRatedProductsRespectsMinRatings(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertRating(1, 11, 0.8);
			$this->insertRating(2, 11, 0.7);
			// product 10 has 1 rating, product 11 has 2 — minRatings=2 should exclude 10
			$result     = $this->stats->topRatedProducts(minRatings: 2);
			$productIds = array_column($result, 'product_id');
			$this->assertNotContains(10, $productIds);
			$this->assertContains(11, $productIds);
		}
		
		// =========================================================================
		// numLinks
		// =========================================================================
		
		public function testNumLinksReturnsZeroWhenEmpty(): void {
			$this->assertSame(0, $this->stats->numLinks());
		}
		
		public function testNumLinksCountsRows(): void {
			$this->insertLink(1, 2, 3);
			$this->insertLink(2, 1, 3);
			$this->insertLink(1, 3, 2);
			$this->assertSame(3, $this->stats->numLinks());
		}
		
		// =========================================================================
		// Category isolation
		// =========================================================================
		
		public function testNumMembersIsolatedByCategory(): void {
			$this->insertRating(1, 10, 0.8, 1);
			$this->insertRating(2, 10, 0.8, 2);
			$this->assertSame(1, $this->stats->numMembers(1));
			$this->assertSame(1, $this->stats->numMembers(2));
		}
		
		public function testNumLinksIsolatedByCategory(): void {
			$this->insertLink(1, 2, 3, 0.0, 1);
			$this->insertLink(1, 2, 3, 0.0, 2);
			$this->assertSame(1, $this->stats->numLinks(1));
			$this->assertSame(1, $this->stats->numLinks(2));
		}
	}