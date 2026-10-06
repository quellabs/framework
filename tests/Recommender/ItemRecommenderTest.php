<?php

	namespace Quellabs\Recommender\Tests;

	use Quellabs\Recommender\RecommendationSource;
	use Quellabs\Recommender\ItemRecommender;
	use Quellabs\Recommender\PredictionResult;
	use Quellabs\Recommender\RecommendationResult;
	use Quellabs\Recommender\ArrayEligibilityProvider;
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

		/**
		 * Extract item IDs from a list of recommendation or prediction results, preserving order.
		 * @param array<int, RecommendationResult|PredictionResult> $results Results carrying an item ID
		 * @return array<int, int> Item IDs in result order
		 */
		private function itemIds(array $results): array {
			return array_map(fn($result) => $result->productId, $results);
		}

		/** Verify support, ordering, legacy parity, and the rejected-item exclusion.
		 * @return void
		 */
		public function testSlopePredictionsCarrySupportAndOrdering(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 40, -1.0);
			$this->insertLink(10, 20, 3, 0.3);
			$this->insertLink(20, 10, 3, -0.3);
			$this->insertLink(10, 30, 2, 0.2);
			$this->insertLink(10, 40, 5, 0.5);

			$member = $this->recommender->memberPredictions(1);
			$this->assertSame([20, 30], $this->itemIds($member));
			$this->assertSame(3, $member[0]->supportCount);
			$this->assertEqualsWithDelta($this->recommender->memberPrediction(1, 20)->predictedRating,
				$member[0]->predictedRating, 0.00001);
			$this->assertSame([20], $this->itemIds($this->recommender->memberPredictions(1, minSupport: 3)));

			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.8);
			$visitor->setRating(40, -1.0);
			$results = $this->recommender->visitorPredictions($visitor);
			$this->assertSame([20, 30], $this->itemIds($results));
			$this->assertSame(20, $this->recommender->visitorPredictions($visitor, limit: 1)[0]->productId);
			$this->assertSame(30, $this->recommender->visitorPredictions($visitor, new ArrayEligibilityProvider([30]), 1)[0]->productId);
			$this->assertEqualsWithDelta($this->recommender->visitorPrediction($visitor, 20)->predictedRating,
				$results[0]->predictedRating, 0.00001);
		}

		/** @return void */
		public function testSlopePredictionsHandleRejectedHistoryAndSupportTies(): void {
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, -1.0);
			$this->insertRating(1, 10, -1.0);
			$this->insertLink(10, 20, 3, 0.0);
			$this->assertSame([], $this->recommender->memberPredictions(1));
			$this->assertSame([], $this->recommender->visitorPredictions($visitor));
			$this->assertNull($this->recommender->visitorPrediction($visitor, 20));
			$this->insertRating(1, 11, 0.8);
			$visitor->setRating(11, 0.8);
			$this->insertLink(11, 20, 2, 0.0);
			$this->insertLink(20, 11, 2, 0.0);
			$this->insertLink(11, 30, 3, 0.0);
			$this->insertLink(11, 40, 3, 0.0);
			$this->assertSame([30, 40, 20], $this->itemIds($this->recommender->memberPredictions(1)));
			$this->assertSame([30, 40, 20], $this->itemIds($this->recommender->visitorPredictions($visitor)));
			$this->assertNull($this->recommender->memberPrediction(1, 20, 3));
			$this->assertNull($this->recommender->visitorPrediction($visitor, 20, 3));
			$this->assertSame([], $this->recommender->memberPredictions(1, category: 2));
		}

		/** @return void */
		public function testVisitorPredictionBatchesLargeHistory(): void {
			$visitor = new VisitorContext($this->config);
			for ($id = 1; $id <= 501; $id++) {
				$visitor->setRating($id, 0.8);
			}
			$this->insertLink(501, 600, 4, 0.4);
			$result = $this->recommender->visitorPredictions($visitor);
			$this->assertCount(1, $result);
			$this->assertSame(600, $result[0]->productId);
			$this->assertSame(4, $result[0]->supportCount);
			$this->assertEqualsWithDelta(0.9, $result[0]->predictedRating, 1e-8);
		}

		/** @return void */
		public function testVisitorPredictionCleansTemporaryTablesAfterQueryFailure(): void {
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.8);
			$logger = new SqlCaptureLogger();
			$driver = $this->connection->getDriver();
			$previousLogger = $driver->getLogger();
			$this->connection->execute('CREATE TEMPORARY TABLE vogoo_links (dummy INT)');
			$driver->setLogger($logger);
			try {
				try {
					$this->recommender->visitorPredictions($visitor);
					$this->fail('The malformed link table did not cause a query failure.');
				} catch (\Cake\Database\Exception\QueryException) {
					$this->assertTrue(true);
				}
			} finally {
				$driver->disableQueryLogging();
				if ($previousLogger !== null) {
					$driver->setLogger($previousLogger);
				}
				$this->connection->execute('DROP TEMPORARY TABLE vogoo_links');
			}
			$created = array_values(array_filter($logger->queries,
				fn($query) => str_contains($query, 'CREATE TEMPORARY TABLE vogoo_visitor_prediction_input_')));
			$this->assertCount(1, $created);
			$this->assertMatchesRegularExpression('/CREATE TEMPORARY TABLE (vogoo_visitor_prediction_input_[a-f0-9]+)/',
				$created[0]);
			preg_match('/CREATE TEMPORARY TABLE (vogoo_visitor_prediction_input_[a-f0-9]+)/',
				$created[0], $matches);
			$this->assertContains("DROP TEMPORARY TABLE {$matches[1]}", $logger->queries);
			try {
				$this->connection->execute("SELECT product_id FROM {$matches[1]}");
				$this->fail('Visitor input table remained after failure.');
			} catch (\Cake\Database\Exception\QueryException) {
				$this->assertTrue(true);
			}
		}

		/** @return void */
		public function testPredictionRejectsNonpositiveSupportThreshold(): void {
			$visitor = new VisitorContext($this->config);
			foreach ([
				fn() => $this->recommender->memberPrediction(1, 20, 0),
				fn() => $this->recommender->memberPredictions(1, minSupport: 0),
				fn() => $this->recommender->visitorPrediction($visitor, 20, 0),
				fn() => $this->recommender->visitorPredictions($visitor, minSupport: 0),
			] as $predict) {
				try {
					$predict();
					$this->fail('Nonpositive minimum support was accepted.');
				} catch (\InvalidArgumentException) {
					$this->assertTrue(true);
				}
			}
		}

		// =========================================================================
		// linkedProducts
		// =========================================================================

		public function testLinkedProductsReturnsEmptyWhenNoLinks(): void {
			$this->assertSame([], $this->recommender->linkedProducts(1));
		}

		public function testLinkedProductsReturnsLinkedProducts(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$result = $this->recommender->linkedProducts(1);
			$this->assertEqualsCanonicalizing([2, 3], $this->itemIds($result));
		}

		public function testLinkedProductsDefaultLimitIsTen(): void {
			for ($product = 2; $product <= 13; $product++) {
				$this->insertLink(1, $product, 1);
			}

			$this->assertCount(10, $this->recommender->linkedProducts(1));
			$this->assertCount(12, $this->recommender->linkedProducts(1, limit: 0));
		}

		public function testNegativeProductIdIsRejected(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->recommender->linkedProducts(-1);
		}

		public function testLinkedProductsScoreIsTheLikedCount(): void {
			$this->insertLink(1, 2, 5);
			$result = $this->recommender->linkedProducts(1);
			$this->assertSame(RecommendationSource::ItemLinks, $result[0]->source);
			$this->assertEqualsWithDelta(5.0, $result[0]->score, 0.00001);
		}

		public function testLinkedItemsOrderedByCountDescending(): void {
			$this->insertLink(1, 2, 3);
			$this->insertLink(1, 3, 10);
			$this->insertLink(1, 4, 5);
			$result = $this->recommender->linkedProducts(1);
			$this->assertSame([3, 4, 2], $this->itemIds($result));
		}

		public function testLinkedItemsRespectsLimit(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$this->insertLink(1, 4, 1);
			$result = $this->recommender->linkedProducts(1, limit: 2);
			$this->assertCount(2, $result);
		}

		public function testLinkedItemsRespectsFilter(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$result = $this->recommender->linkedProducts(1, new ArrayEligibilityProvider([2]));
			$this->assertSame([2], $this->itemIds($result));
		}

		/** Filtering must happen before a query limit.
		 * @return void
		 */
		public function testLinkedItemsFilterFillsLimit(): void {
			$this->insertLink(1, 2, 10);
			$this->insertLink(1, 3, 5);
			$this->assertSame([3], $this->itemIds($this->recommender->linkedProducts(1, new ArrayEligibilityProvider([3]), 1)));
		}

		/** Large allowlists use the bounded temporary-table path.
		 * @return void
		 */
		public function testLargeAllowedListStillFillsLimit(): void {
			$this->insertLink(1, 2, 10);
			$this->insertLink(1, 3, 5);
			$allowed = array_merge(range(1000, 1500), [3]);
			$this->assertSame([3], $this->itemIds($this->recommender->linkedProducts(1, new ArrayEligibilityProvider($allowed), 1)));
			$this->assertSame([3], $this->itemIds($this->recommender->linkedProducts(1, new ArrayEligibilityProvider([3]), 1)));
		}

		// =========================================================================
		// memberRecommendations (item_links source)
		// =========================================================================

		public function testMemberRecommendationsReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.8);
			$this->assertSame([], $this->recommender->memberRecommendations(1));
		}

		public function testMemberRecommendationsReturnsUnratedLinkedItems(): void {
			// Member rated product 10; product 10 is linked to 20 and 30
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$result = $this->recommender->memberRecommendations(1);
			$this->assertEqualsCanonicalizing([20, 30], $this->itemIds($result));
		}

		public function testMemberRecommendationsExcludesAlreadyRatedItems(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertRating(1, 20, 0.5);
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$result = $this->itemIds($this->recommender->memberRecommendations(1));
			$this->assertNotContains(20, $result);
			$this->assertContains(30, $result);
		}

		public function testMemberRecommendationsRespectsLimit(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 10);
			$this->insertLink(10, 30, 8);
			$this->insertLink(10, 40, 5);
			$result = $this->recommender->memberRecommendations(1, limit: 2);
			$this->assertCount(2, $result);
		}

		/** Allowed candidates beyond the first raw result still fill the limit.
		 * @return void
		 */
		public function testMemberRecommendationsFilterFillsLimit(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 10);
			$this->insertLink(10, 30, 5);
			$this->assertSame([30], $this->itemIds($this->recommender->memberRecommendations(1, new ArrayEligibilityProvider([30]), 1)));
		}

		// =========================================================================
		// memberReasons
		// =========================================================================

		public function testMemberReasonsReturnsRatedLinkedProducts(): void {
			// Member rated 10 and 20; product 30 is linked to both
			$this->insertRating(1, 10, 0.9);
			$this->insertRating(1, 20, 0.8);
			$this->insertLink(30, 10, 5);
			$this->insertLink(30, 20, 3);
			$result = $this->recommender->memberReasons(1, 30);
			$this->assertEqualsCanonicalizing([10, 20], $this->itemIds($result));
			$this->assertSame(5.0, $result[0]->score);
		}

		public function testMemberReasonsScoreIsLikedCountOfLink(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(30, 10, 5);
			$result = $this->recommender->memberReasons(1, 30);
			$this->assertSame(RecommendationSource::ItemLinks, $result[0]->source);
			$this->assertSame(5.0, $result[0]->score);
		}

		public function testVisitorReasonsReturnsRatedLinkedProducts(): void {
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$visitor->setRating(20, 0.8);
			$this->insertLink(30, 10, 5);
			$this->insertLink(30, 20, 3);
			$result = $this->recommender->visitorReasons($visitor, 30);
			$this->assertSame([10, 20], $this->itemIds($result));
			$this->assertSame([5.0, 3.0], array_map(fn($r) => $r->score, $result));
		}

		public function testVisitorReasonsReturnsEmptyWhenNoRatings(): void {
			$this->assertSame([], $this->recommender->visitorReasons(new VisitorContext($this->config), 30));
		}

		public function testMemberReasonsReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.9);
			$this->assertSame([], $this->recommender->memberReasons(1, 99));
		}

		// =========================================================================
		// slopeProducts
		// =========================================================================

		public function testSlopeItemsReturnsItemsWithDiffScore(): void {
			$this->insertLink(1, 2, 3, 0.6);
			$this->insertLink(1, 3, 2, 0.2);
			$result = $this->recommender->slopeProducts(1);
			$this->assertCount(2, $result);
			$this->assertSame(RecommendationSource::SlopeOne, $result[0]->source);
			$this->assertIsFloat($result[0]->score);
		}

		public function testSlopeItemsOrderedByAvgDiffDescending(): void {
			// item 2: diff=0.6/3=0.2, item 3: diff=0.9/2=0.45
			$this->insertLink(1, 2, 3, 0.6);
			$this->insertLink(1, 3, 2, 0.9);
			$result = $this->recommender->slopeProducts(1);
			$this->assertSame([3, 2], $this->itemIds($result));
		}

		public function testSlopeItemsRespectsMinSupport(): void {
			$this->insertLink(1, 2, 1, 0.5);
			$this->insertLink(1, 3, 5, 0.5);
			$result = $this->recommender->slopeProducts(1, minSupport: 3);
			$this->assertSame([3], $this->itemIds($result));
		}

		/** Slope allowlists are applied before SQL limits.
		 * @return void
		 */
		public function testSlopeItemsFilterFillsLimit(): void {
			$this->insertLink(1, 2, 2, 0.8);
			$this->insertLink(1, 3, 2, 0.2);
			$this->assertSame(3, $this->recommender->slopeProducts(1, eligibility: new ArrayEligibilityProvider([3]), limit: 1)[0]->productId);
		}

		// =========================================================================
		// memberPrediction
		// =========================================================================

		public function testMemberPredictionReturnsNullWithNoData(): void {
			$this->assertNull($this->recommender->memberPrediction(1, 99));
		}

		public function testMemberPredictionReturnsPredictedRating(): void {
			// Member rated product 2 at 0.8; link 1->2 has cnt=1, diff_slope=-0.1
			// predicted = (0.8 * 1 - (-0.1)) / 1 = 0.9
			$this->insertRating(1, 2, 0.8);
			$this->insertLink(1, 2, 1, -0.1);
			$result = $this->recommender->memberPrediction(1, 1);
			$this->assertNotNull($result);
			$this->assertEqualsWithDelta(0.9, $result->predictedRating, 0.0001);
		}

		/** A single prediction ignores disinterest and matches the all-item result.
		 * @return void
		 */
		public function testMemberPredictionMatchesAllAndIgnoresDisinterest(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 30, -1.0);
			$this->insertLink(10, 20, 2, 0.2);
			$this->insertLink(20, 10, 2, -0.2);
			$this->insertLink(20, 30, 2, 0.1);
			$all = $this->recommender->memberPredictions(1);
			$this->assertCount(1, $all);
			$this->assertSame(20, $all[0]->productId);
			$this->assertEqualsWithDelta($all[0]->predictedRating,
				$this->recommender->memberPrediction(1, 20)->predictedRating, 0.00001);
		}

		public function testMemberPredictionClampsToOne(): void {
			$this->insertRating(1, 2, 1.0);
			$this->insertLink(1, 2, 1, 0.5);
			$result = $this->recommender->memberPrediction(1, 1);
			$this->assertLessThanOrEqual(1.0, $result->predictedRating);
		}

		public function testMemberPredictionClampsToZero(): void {
			$this->insertRating(1, 2, 0.0);
			$this->insertLink(1, 2, 1, -0.5);
			$result = $this->recommender->memberPrediction(1, 1);
			$this->assertGreaterThanOrEqual(0.0, $result->predictedRating);
		}

		// =========================================================================
		// memberPredictions
		// =========================================================================

		public function testMemberPredictionsReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.8);
			$this->assertSame([], $this->recommender->memberPredictions(1));
		}

		public function testMemberPredictionsExcludesAlreadyRatedItems(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 20, 0.5);
			$this->insertLink(10, 20, 2, 0.1);
			$this->insertLink(10, 30, 2, 0.2);
			$productIds = $this->itemIds($this->recommender->memberPredictions(1));
			$this->assertNotContains(10, $productIds);
			$this->assertNotContains(20, $productIds);
		}

		public function testMemberPredictionsAreSortedDescending(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertLink(10, 20, 2, 0.1);
			$this->insertLink(10, 30, 2, -0.1);
			$ratings = array_map(fn($result) => $result->predictedRating, $this->recommender->memberPredictions(1));

			for ($i = 1; $i < count($ratings); $i++) {
				$this->assertGreaterThanOrEqual($ratings[$i], $ratings[$i - 1]);
			}
		}

		// =========================================================================
		// Visitor methods
		// =========================================================================

		public function testVisitorRecommendationsReturnsEmptyForEmptyContext(): void {
			$visitor = new VisitorContext($this->config);
			$this->assertSame([], $this->recommender->visitorRecommendations($visitor));
		}

		public function testVisitorRecommendationsReturnsLinkedItems(): void {
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$result = $this->recommender->visitorRecommendations($visitor);
			$this->assertEqualsCanonicalizing([20, 30], $this->itemIds($result));
		}

		public function testVisitorRecommendationsExcludesAlreadyRatedProducts(): void {
			$this->insertLink(10, 20, 5);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$visitor->setRating(20, 0.5);
			$result = $this->itemIds($this->recommender->visitorRecommendations($visitor));
			$this->assertNotContains(20, $result);
		}

		public function testVisitorPredictionReturnsNullForEmptyContext(): void {
			$visitor = new VisitorContext($this->config);
			$this->assertNull($this->recommender->visitorPrediction($visitor, 1));
		}

		public function testVisitorPredictionReturnsPredictedRating(): void {
			$this->insertLink(1, 2, 1, -0.1);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(2, 0.8);
			$result = $this->recommender->visitorPrediction($visitor, 1);
			$this->assertNotNull($result);
			$this->assertEqualsWithDelta(0.9, $result->predictedRating, 0.0001);
		}

		// =========================================================================
		// Category isolation
		// =========================================================================

		public function testLinkedItemsIsolatedByCategory(): void {
			$this->insertLink(1, 2, 5, 0.0, 1);
			$this->insertLink(1, 3, 5, 0.0, 2);
			$this->assertSame([2], $this->itemIds($this->recommender->linkedProducts(1, category: 1)));
			$this->assertSame([3], $this->itemIds($this->recommender->linkedProducts(1, category: 2)));
		}
	}
