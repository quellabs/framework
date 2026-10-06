<?php
	
	namespace Quellabs\Recommender\Tests;
	
	use Quellabs\Recommender\RatingKind;
	use Quellabs\Recommender\RatingOrder;
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
			$this->engine->setRating(1, 10, 0.8);
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
		
		/**
		 * Assert that a rating write rejects its input with an InvalidArgumentException.
		 * @param callable $write Rating write to attempt
		 * @return void
		 */
		private function assertRejectsWrite(callable $write): void {
			try {
				$write();
				$this->fail('Invalid rating input was accepted.');
			} catch (\InvalidArgumentException) {
				$this->assertTrue(true);
			}
		}

		public function testSetRatingRejectsOutOfRangeValue(): void {
			$this->assertRejectsWrite(fn() => $this->engine->setRating(1, 10, 1.5));
			$this->assertRejectsWrite(fn() => $this->engine->setRating(1, 10, -0.5));
		}

		/** Reject nonfinite values and IDs outside the unsigned schema.
		 * @return void
		 */
		public function testSetRatingRejectsInvalidNumericInput(): void {
			$this->assertRejectsWrite(fn() => $this->engine->setRating(1, 10, NAN));
			$this->assertRejectsWrite(fn() => $this->engine->setRating(1, 10, INF));
			$this->assertRejectsWrite(fn() => $this->engine->setRating(-1, 10, 0.5));
			$this->assertRejectsWrite(fn() => $this->engine->setRating(1, -10, 0.5));
		}

		public function testSetRatingAcceptsNotInterestedSentinel(): void {
			$this->engine->setRating(1, 10, RecommendationConfig::NOT_INTERESTED);
			$row = $this->fetchRatingRow(1, 10);
			$this->assertNotNull($row);
		}

		public function testSetRatingAcceptsBoundaryValues(): void {
			$this->engine->setRating(1, 10, 0.0);
			$this->engine->setRating(1, 11, 1.0);
			$this->assertNotNull($this->fetchRatingRow(1, 10));
			$this->assertNotNull($this->fetchRatingRow(1, 11));
		}
		
		public function testGetRatingReturnsRatingAndTs(): void {
			$this->engine->setRating(1, 10, 0.7);
			$result = $this->engine->memberRating(1, 10);
			$this->assertArrayHasKey('rating', $result);
			$this->assertArrayHasKey('ts', $result);
			$this->assertEqualsWithDelta(0.7, $result['rating'], 0.0001);
		}
		
		public function testMemberRatingReturnsNullWhenNotFound(): void {
			$this->assertNull($this->engine->memberRating(1, 99));
		}
		
		public function testMemberRatingExcludesNotInterestedByDefault(): void {
			$this->engine->setNotInterested(1, 10);
			$this->assertNull($this->engine->memberRating(1, 10));
		}
		
		public function testGetRatingIncludesNotInterestedWhenRequested(): void {
			$this->engine->setNotInterested(1, 10);
			$result = $this->engine->memberRating(1, 10, RatingKind::All);
			$this->assertNotEmpty($result);
			$this->assertEqualsWithDelta(RecommendationConfig::NOT_INTERESTED, $result['rating'], 0.0001);
		}
		
		// =========================================================================
		// setNotInterested
		// =========================================================================
		
		public function testSetNotInterestedStoresSentinel(): void {
			$this->engine->setNotInterested(1, 10);
			$row = $this->fetchRatingRow(1, 10);
			$this->assertNotNull($row);
			$this->assertEqualsWithDelta(RecommendationConfig::NOT_INTERESTED, (float)$row['rating'], 0.0001);
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
			$this->assertSame(2, $this->engine->memberNumRatings(1, RatingKind::NotInterested));
		}
		
		// =========================================================================
		// memberAverageRating
		// =========================================================================
		
		public function testMemberAverageRatingReturnsNullWhenNoRatings(): void {
			$this->assertNull($this->engine->memberAverageRating(1));
		}
		
		public function testMemberAverageRatingKeepsZeroRatingDistinctFromNone(): void {
			$this->engine->setRating(1, 10, 0.0);
			$this->assertSame(0.0, $this->engine->memberAverageRating(1));
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
			$ratings = $this->engine->memberRatings(1, order: RatingOrder::RatingAscending);
			$values = array_map('floatval', array_column($ratings, 'rating'));
			
			for ($i = 1; $i < count($values); $i++) {
				$this->assertLessThanOrEqual($values[$i], $values[$i - 1]);
			}
		}
		
		public function testMemberRatingsOrderByRatingDescending(): void {
			$this->engine->setRating(1, 10, 0.8);
			$this->engine->setRating(1, 11, 0.3);
			$ratings = $this->engine->memberRatings(1, order: RatingOrder::RatingDescending);
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
		
		public function testProductAverageRatingReturnsNullWhenNoRatings(): void {
			$this->assertNull($this->engine->productAverageRating(99));
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
		// recordPurchase / recordClick
		// =========================================================================
		
		public function testRecordPurchaseSetsMaxRating(): void {
			$this->engine->recordPurchase(1, 10);
			$result = $this->engine->memberRating(1, 10);
			$this->assertEqualsWithDelta(1.0, $result['rating'], 0.0001);
		}
		
		public function testRecordClickSetsInitialRating(): void {
			$this->engine->recordClick(1, 10);
			$result = $this->engine->memberRating(1, 10);
			$this->assertEqualsWithDelta(0.7, $result['rating'], 0.0001);
		}
		
		public function testRecordClickIncrementsExistingRating(): void {
			$this->engine->setRating(1, 10, 0.5);
			$this->engine->recordClick(1, 10);
			$result = $this->engine->memberRating(1, 10);
			$this->assertEqualsWithDelta(0.51, $result['rating'], 0.0001);
		}
		
		public function testRecordClickDoesNotExceedOne(): void {
			$this->engine->setRating(1, 10, 1.0);
			$this->engine->recordClick(1, 10);
			$result = $this->engine->memberRating(1, 10);
			$this->assertEqualsWithDelta(1.0, $result['rating'], 0.0001);
		}

		/** A click near the upper boundary clamps before rating validation.
		 * @return void
		 */
		public function testRecordClickClampsNearOne(): void {
			$this->engine->setRating(1, 10, 0.995);
			$this->engine->recordClick(1, 10);
			$this->assertEqualsWithDelta(1.0, $this->engine->memberRating(1, 10)['rating'], 0.00001);
		}
		
		// =========================================================================
		// Category isolation
		// =========================================================================
		
		public function testRatingsAreIsolatedByCategory(): void {
			$this->engine->setRating(1, 10, 0.8, 1);
			$this->engine->setRating(1, 10, 0.3, 2);
			$this->assertEqualsWithDelta(0.8, $this->engine->memberRating(1, 10, category: 1)['rating'], 0.0001);
			$this->assertEqualsWithDelta(0.3, $this->engine->memberRating(1, 10, category: 2)['rating'], 0.0001);
		}
		
		public function testMemberNumRatingsResolvesDefaultCategory(): void {
			$config = new RecommendationConfig(category: 2);
			$engine = new RecommendationEngine($this->connection, $config);
			$engine->setRating(1, 10, 0.8);   // goes into category 2
			$this->assertSame(0, $engine->memberNumRatings(1, category: 1));
			$this->assertSame(1, $engine->memberNumRatings(1, category: 2));
		}
	}
