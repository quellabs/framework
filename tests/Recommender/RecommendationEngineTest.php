<?php
	
	namespace Quellabs\Recommender\Tests;
	
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\RecommendationEngine;
	
	/**
	 * Integration tests for RecommendationEngine.
	 * Requires a live MySQL database — see tests/bootstrap.php.
	 */
	class RecommendationEngineTest extends IntegrationTestCase {
		
		private RecommendationEngine $engine;
		
		protected function setUp(): void {
			parent::setUp();
			$this->engine = new RecommendationEngine($this->connection, $this->config);
		}
		
		// =========================================================================
		// setRating / getRating
		// =========================================================================
		
		public function testSetRatingInsertsRow(): void {
			$result = $this->engine->setRating(1, 10, 0.8);
			$this->assertTrue($result);
			$row = $this->fetchRatingRow(1, 10);
			$this->assertNotNull($row);
			$this->assertEqualsWithDelta(0.8, (float)$row['rating'], 0.0001);
		}
		
		public function testSetRatingUpdatesExistingRow(): void {
			$this->engine->setRating(1, 10, 0.5);
			$this->engine->setRating(1, 10, 0.9);
			$row = $this->fetchRatingRow(1, 10);
			$this->assertEqualsWithDelta(0.9, (float)$row['rating'], 0.0001);
		}
		
		public function testSetRatingReturnsFalseForOutOfRangeValue(): void {
			$this->assertFalse($this->engine->setRating(1, 10, 1.5));
			$this->assertFalse($this->engine->setRating(1, 10, -0.5));
		}
		
		public function testSetRatingAcceptsNotInterestedSentinel(): void {
			$result = $this->engine->setRating(1, 10, $this->config->getNotInterested());
			$this->assertTrue($result);
			$row = $this->fetchRatingRow(1, 10);
			$this->assertNotNull($row);
		}
		
		public function testSetRatingAcceptsBoundaryValues(): void {
			$this->assertTrue($this->engine->setRating(1, 10, 0.0));
			$this->assertTrue($this->engine->setRating(1, 11, 1.0));
		}
		
		public function testGetRatingReturnsRatingAndTs(): void {
			$this->engine->setRating(1, 10, 0.7);
			$result = $this->engine->getRating(1, 10);
			$this->assertArrayHasKey('rating', $result);
			$this->assertArrayHasKey('ts', $result);
			$this->assertEqualsWithDelta(0.7, $result['rating'], 0.0001);
		}
		
		public function testGetRatingReturnsEmptyArrayWhenNotFound(): void {
			$this->assertSame([], $this->engine->getRating(1, 99));
		}
		
		public function testGetRatingExcludesNotInterestedByDefault(): void {
			$this->engine->setNotInterested(1, 10);
			$this->assertSame([], $this->engine->getRating(1, 10));
		}
		
		public function testGetRatingIncludesNotInterestedWhenRequested(): void {
			$this->engine->setNotInterested(1, 10);
			$result = $this->engine->getRating(1, 10, notInterested: true);
			$this->assertNotEmpty($result);
			$this->assertEqualsWithDelta($this->config->getNotInterested(), $result['rating'], 0.0001);
		}
		
		// =========================================================================
		// setNotInterested
		// =========================================================================
		
		public function testSetNotInterestedStoresSentinel(): void {
			$this->engine->setNotInterested(1, 10);
			$row = $this->fetchRatingRow(1, 10);
			$this->assertNotNull($row);
			$this->assertEqualsWithDelta($this->config->getNotInterested(), (float)$row['rating'], 0.0001);
		}
		
		// =========================================================================
		// deleteRating
		// =========================================================================
		
		public function testDeleteRatingRemovesRow(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->deleteRating(1, 10);
			$this->assertNull($this->fetchRatingRow(1, 10));
		}
		
		public function testDeleteRatingOnNonExistentRowIsNoop(): void {
			$this->engine->deleteRating(99, 99);
			$this->assertNull($this->fetchRatingRow(99, 99));
		}
		
		// =========================================================================
		// memberNumRatings
		// =========================================================================
		
		public function testMemberNumRatingsReturnsZeroWhenNoRatings(): void {
			$this->assertSame(0, $this->engine->memberNumRatings(1));
		}
		
		public function testMemberNumRatingsCountsRealRatings(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(1, 11, 0.5);
			$this->assertSame(2, $this->engine->memberNumRatings(1));
		}
		
		public function testMemberNumRatingsExcludesNotInterestedByDefault(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setNotInterested(1, 11);
			$this->assertSame(1, $this->engine->memberNumRatings(1));
		}
		
		public function testMemberNumRatingsCountsNotInterestedWhenRequested(): void {
			$this->engine->setNotInterested(1, 10);
			$this->engine->setNotInterested(1, 11);
			$this->assertSame(2, $this->engine->memberNumRatings(1, realRatings: false, notInterested: true));
		}
		
		// =========================================================================
		// memberAverageRating
		// =========================================================================
		
		public function testMemberAverageRatingReturnsZeroWhenNoRatings(): void {
			$this->assertEqualsWithDelta(0.0, $this->engine->memberAverageRating(1), 0.0001);
		}
		
		public function testMemberAverageRatingCalculatesCorrectly(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(1, 11, 0.4);
			$this->assertEqualsWithDelta(0.6, $this->engine->memberAverageRating(1), 0.0001);
		}
		
		// =========================================================================
		// memberRatings
		// =========================================================================
		
		public function testMemberRatingsReturnsAllRatings(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(1, 11, 0.5);
			$ratings = $this->engine->memberRatings(1);
			$this->assertCount(2, $ratings);
		}
		
		public function testMemberRatingsOrderByRatingAscending(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(1, 11, 0.3);
			$this->engine->setRating(1, 12, 0.5);
			$ratings = $this->engine->memberRatings(1, orderByRating: true, ascending: true);
			$values = array_map('floatval', array_column($ratings, 'rating'));
			
			for ($i = 1; $i < count($values); $i++) {
				$this->assertLessThanOrEqual($values[$i], $values[$i - 1]);
			}
		}
		
		public function testMemberRatingsOrderByRatingDescending(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(1, 11, 0.3);
			$ratings = $this->engine->memberRatings(1, orderByRating: true, ascending: false);
			$values = array_column($ratings, 'rating');
			$this->assertGreaterThanOrEqual((float)$values[1], (float)$values[0]);
		}
		
		// =========================================================================
		// productNumRatings / productAverageRating
		// =========================================================================
		
		public function testProductNumRatingsCountsRatings(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(2, 10, 0.5);
			$this->assertSame(2, $this->engine->productNumRatings(10));
		}
		
		public function testProductAverageRatingCalculatesCorrectly(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(2, 10, 0.4);
			$this->assertEqualsWithDelta(0.6, $this->engine->productAverageRating(10), 0.0001);
		}
		
		public function testProductAverageRatingReturnsZeroWhenNoRatings(): void {
			$this->assertEqualsWithDelta(0.0, $this->engine->productAverageRating(99), 0.0001);
		}
		
		// =========================================================================
		// deleteMember / deleteProduct
		// =========================================================================
		
		public function testDeleteMemberRemovesAllRatings(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(1, 11, 0.5);
			$this->engine->deleteMember(1);
			$this->assertSame(0, $this->engine->memberNumRatings(1));
		}
		
		public function testDeleteProductRemovesAllRatings(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(2, 10, 0.5);
			$this->engine->deleteProduct(10);
			$this->assertSame(0, $this->engine->productNumRatings(10));
		}
		
		// =========================================================================
		// automaticRating
		// =========================================================================
		
		public function testAutomaticRatingPurchaseSetsMaxRating(): void {
			$this->engine->automaticRating(1, 10, purchase: true);
			$result = $this->engine->getRating(1, 10);
			$this->assertEqualsWithDelta(1.0, $result['rating'], 0.0001);
		}
		
		public function testAutomaticRatingClickSetsInitialRating(): void {
			$this->engine->automaticRating(1, 10, purchase: false);
			$result = $this->engine->getRating(1, 10);
			$this->assertEqualsWithDelta(0.7, $result['rating'], 0.0001);
		}
		
		public function testAutomaticRatingClickIncrementsExistingRating(): void {
			$this->engine->setRating(1, 10, 0.5);
			$this->engine->automaticRating(1, 10, purchase: false);
			$result = $this->engine->getRating(1, 10);
			$this->assertEqualsWithDelta(0.51, $result['rating'], 0.0001);
		}
		
		public function testAutomaticRatingClickDoesNotExceedOne(): void {
			$this->engine->setRating(1, 10, 1.0);
			$this->engine->automaticRating(1, 10, purchase: false);
			$result = $this->engine->getRating(1, 10);
			$this->assertEqualsWithDelta(1.0, $result['rating'], 0.0001);
		}
		
		// =========================================================================
		// Category isolation
		// =========================================================================
		
		public function testRatingsAreIsolatedByCategory(): void {
			$this->engine->setRating(1, 10, 0.8, 1);
			$this->engine->setRating(1, 10, 0.3, 2);
			$this->assertEqualsWithDelta(0.8, $this->engine->getRating(1, 10, category: 1)['rating'], 0.0001);
			$this->assertEqualsWithDelta(0.3, $this->engine->getRating(1, 10, category: 2)['rating'], 0.0001);
		}
		
		public function testMemberNumRatingsResolvesDefaultCategory(): void {
			$config = new RecommendationConfig(category: 2);
			$engine = new RecommendationEngine($this->connection, $config);
			$engine->setRating(1, 10, 0.8);   // goes into category 2
			$this->assertSame(0, $engine->memberNumRatings(1, category: 1));
			$this->assertSame(1, $engine->memberNumRatings(1, category: 2));
		}
	}