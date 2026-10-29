<?php

	namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\Sources\ItemLinksSource;
use Quellabs\Recommender\Sources\SlopeOneSource;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\RecommendationResult;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\VisitorContext;

	/**
	 * Integration tests for item-to-item, prediction and reasons lookups on the item-links and Slope One sources.
	 * Requires a live MySQL database — see tests/bootstrap.php.
	 */
	class ItemLookupsTest extends IntegrationTestCase {
		use ItemLinkSlates;

		/** @var SlopeOneSource Slope One predictions and product lookups */
		private SlopeOneSource $slopeOne;

		/** @var ItemLinksSource Item-links candidates and reasons */
		private ItemLinksSource $itemLinks;

		/** @return void */
		protected function setUp(): void {
			parent::setUp();
			$this->slopeOne = new SlopeOneSource($this->connection, $this->config);
			$this->itemLinks = new ItemLinksSource($this->connection, $this->config);
		}

		/**
		 * Extract item IDs from a list of recommendation or prediction results, preserving order.
		 * @param array<int, RecommendationResult|RecommendationResult> $results Results carrying an item ID
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

			$member = $this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings());
			$this->assertSame([20, 30], $this->itemIds($member));
			$this->assertSame(3, $member[0]->supportCount);
			$this->assertEqualsWithDelta($this->slopeOne->predict(Subject::member(1), 20)->score,
				$member[0]->score, 0.00001);
			$this->assertSame([20], $this->itemIds($this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings(minSupport: 3))));

			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.8);
			$visitor->setRating(40, -1.0);
			$results = $this->slopeOne->candidates(Subject::visitor($visitor), null, 10, new SourceSettings());
			$this->assertSame([20, 30], $this->itemIds($results));
			$this->assertSame(20, $this->slopeOne->candidates(Subject::visitor($visitor), null, 1, new SourceSettings())[0]->productId);
			$this->assertSame(30, $this->slopeOne->candidates(Subject::visitor($visitor), new ArrayEligibilityProvider([30]), 1, new SourceSettings())[0]->productId);
			$this->assertEqualsWithDelta($this->slopeOne->predict(Subject::visitor($visitor), 20)->score,
				$results[0]->score, 0.00001);
		}

		/** @return void */
		public function testSlopePredictionsHandleRejectedHistoryAndSupportTies(): void {
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, -1.0);
			$this->insertRating(1, 10, -1.0);
			$this->insertLink(10, 20, 3, 0.0);
			$this->assertSame([], $this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings()));
			$this->assertSame([], $this->slopeOne->candidates(Subject::visitor($visitor), null, 10, new SourceSettings()));
			$this->assertNull($this->slopeOne->predict(Subject::visitor($visitor), 20));
			$this->insertRating(1, 11, 0.8);
			$visitor->setRating(11, 0.8);
			$this->insertLink(11, 20, 2, 0.0);
			$this->insertLink(20, 11, 2, 0.0);
			$this->insertLink(11, 30, 3, 0.0);
			$this->insertLink(11, 40, 3, 0.0);
			$this->assertSame([30, 40, 20], $this->itemIds($this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings())));
			$this->assertSame([30, 40, 20], $this->itemIds($this->slopeOne->candidates(Subject::visitor($visitor), null, 10, new SourceSettings())));
			$this->assertNull($this->slopeOne->predict(Subject::member(1), 20, 3));
			$this->assertNull($this->slopeOne->predict(Subject::visitor($visitor), 20, 3));
			$this->assertSame([], $this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings(), 2));
		}

		/** @return void */
		public function testVisitorPredictionBatchesLargeHistory(): void {
			$visitor = new VisitorContext($this->config);
			for ($id = 1; $id <= 501; $id++) {
				$visitor->setRating($id, 0.8);
			}
			$this->insertLink(501, 600, 4, 0.4);
			$result = $this->slopeOne->candidates(Subject::visitor($visitor), null, 10, new SourceSettings());
			$this->assertCount(1, $result);
			$this->assertSame(600, $result[0]->productId);
			$this->assertSame(4, $result[0]->supportCount);
			$this->assertEqualsWithDelta(0.9, $result[0]->score, 1e-8);
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
					$this->slopeOne->candidates(Subject::visitor($visitor), null, 10, new SourceSettings());
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
				fn($query) => str_contains($query, 'CREATE TEMPORARY TABLE vogoo_source_input_')));
			$this->assertCount(1, $created);
			$this->assertMatchesRegularExpression('/CREATE TEMPORARY TABLE (vogoo_source_input_[a-f0-9]+)/',
				$created[0]);
			preg_match('/CREATE TEMPORARY TABLE (vogoo_source_input_[a-f0-9]+)/',
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
				fn() => $this->slopeOne->predict(Subject::member(1), 20, 0),
				fn() => $this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings(minSupport: 0)),
				fn() => $this->slopeOne->predict(Subject::visitor($visitor), 20, 0),
				fn() => $this->slopeOne->candidates(Subject::visitor($visitor), null, 10, new SourceSettings(minSupport: 0)),
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
			$this->assertSame([], $this->linked(1));
		}

		public function testLinkedProductsReturnsLinkedProducts(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$result = $this->linked(1);
			$this->assertEqualsCanonicalizing([2, 3], $this->itemIds($result));
		}

		public function testLinkedProductsDefaultLimitIsTen(): void {
			for ($product = 2; $product <= 13; $product++) {
				$this->insertLink(1, $product, 1);
			}

			$this->assertCount(10, $this->linked(1, null, 10));
			$this->assertCount(12, $this->linked(1, null, 0));
		}

		public function testNegativeProductIdIsRejected(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->linked(-1);
		}

		public function testLinkedProductsScoreIsTheLikedCount(): void {
			$this->insertLink(1, 2, 5);
			$result = $this->linked(1);
			$this->assertSame(RecommendationSource::ItemLinks, $result[0]->source);
			$this->assertEqualsWithDelta(5.0, $result[0]->score, 0.00001);
		}

		public function testLinkedItemsOrderedByCountDescending(): void {
			$this->insertLink(1, 2, 3);
			$this->insertLink(1, 3, 10);
			$this->insertLink(1, 4, 5);
			$result = $this->linked(1);
			$this->assertSame([3, 4, 2], $this->itemIds($result));
		}

		public function testLinkedItemsRespectsLimit(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$this->insertLink(1, 4, 1);
			$result = $this->linked(1, null, 2);
			$this->assertCount(2, $result);
		}

		public function testLinkedItemsRespectsFilter(): void {
			$this->insertLink(1, 2, 5);
			$this->insertLink(1, 3, 3);
			$result = $this->linked(1, new ArrayEligibilityProvider([2]));
			$this->assertSame([2], $this->itemIds($result));
		}

		/** Filtering must happen before a query limit.
		 * @return void
		 */
		public function testLinkedItemsFilterFillsLimit(): void {
			$this->insertLink(1, 2, 10);
			$this->insertLink(1, 3, 5);
			$this->assertSame([3], $this->itemIds($this->linked(1, new ArrayEligibilityProvider([3]), 1)));
		}

		/** Large allowlists use the bounded temporary-table path.
		 * @return void
		 */
		public function testLargeAllowedListStillFillsLimit(): void {
			$this->insertLink(1, 2, 10);
			$this->insertLink(1, 3, 5);
			$allowed = array_merge(range(1000, 1500), [3]);
			$this->assertSame([3], $this->itemIds($this->linked(1, new ArrayEligibilityProvider($allowed), 1)));
			$this->assertSame([3], $this->itemIds($this->linked(1, new ArrayEligibilityProvider([3]), 1)));
		}

		// =========================================================================
		// memberLinks (item_links source)
		// =========================================================================

		public function testMemberLinksReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.8);
			$this->assertSame([], $this->memberLinks(1));
		}

		public function testMemberLinksReturnsUnratedLinkedItems(): void {
			// Member rated product 10; product 10 is linked to 20 and 30
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$result = $this->memberLinks(1);
			$this->assertEqualsCanonicalizing([20, 30], $this->itemIds($result));
		}

		public function testMemberLinksExcludesAlreadyRatedItems(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertRating(1, 20, 0.5);
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$result = $this->itemIds($this->memberLinks(1));
			$this->assertNotContains(20, $result);
			$this->assertContains(30, $result);
		}

		public function testMemberLinksRespectsLimit(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 10);
			$this->insertLink(10, 30, 8);
			$this->insertLink(10, 40, 5);
			$result = $this->memberLinks(1, limit: 2);
			$this->assertCount(2, $result);
		}

		/** Allowed candidates beyond the first raw result still fill the limit.
		 * @return void
		 */
		public function testMemberLinksFilterFillsLimit(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(10, 20, 10);
			$this->insertLink(10, 30, 5);
			$this->assertSame([30], $this->itemIds($this->memberLinks(1, new ArrayEligibilityProvider([30]), 1)));
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
			$result = $this->itemLinks->reasons(Subject::member(1), 30);
			$this->assertEqualsCanonicalizing([10, 20], $this->itemIds($result));
			$this->assertSame(5.0, $result[0]->score);
		}

		public function testMemberReasonsScoreIsLikedCountOfLink(): void {
			$this->insertRating(1, 10, 0.9);
			$this->insertLink(30, 10, 5);
			$result = $this->itemLinks->reasons(Subject::member(1), 30);
			$this->assertSame(RecommendationSource::ItemLinks, $result[0]->source);
			$this->assertSame(5.0, $result[0]->score);
		}

		public function testVisitorReasonsReturnsRatedLinkedProducts(): void {
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$visitor->setRating(20, 0.8);
			$this->insertLink(30, 10, 5);
			$this->insertLink(30, 20, 3);
			$result = $this->itemLinks->reasons(Subject::visitor($visitor), 30);
			$this->assertSame([10, 20], $this->itemIds($result));
			$this->assertSame([5.0, 3.0], array_map(fn($r) => $r->score, $result));
		}

		public function testVisitorReasonsReturnsEmptyWhenNoRatings(): void {
			$this->assertSame([], $this->itemLinks->reasons(Subject::visitor(new VisitorContext($this->config)), 30));
		}

		public function testMemberReasonsReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.9);
			$this->assertSame([], $this->itemLinks->reasons(Subject::member(1), 99));
		}

		// =========================================================================
		// slopeProducts
		// =========================================================================

		public function testSlopeItemsReturnsItemsWithDiffScore(): void {
			$this->insertLink(1, 2, 3, 0.6);
			$this->insertLink(1, 3, 2, 0.2);
			$result = $this->slope(1);
			$this->assertCount(2, $result);
			$this->assertSame(RecommendationSource::SlopeOne, $result[0]->source);
			$this->assertIsFloat($result[0]->score);
		}

		public function testSlopeItemsOrderedByAvgDiffDescending(): void {
			// item 2: diff=0.6/3=0.2, item 3: diff=0.9/2=0.45
			$this->insertLink(1, 2, 3, 0.6);
			$this->insertLink(1, 3, 2, 0.9);
			$result = $this->slope(1);
			$this->assertSame([3, 2], $this->itemIds($result));
		}

		public function testSlopeItemsRespectsMinSupport(): void {
			$this->insertLink(1, 2, 1, 0.5);
			$this->insertLink(1, 3, 5, 0.5);
			$result = $this->slope(1, null, 10, 3);
			$this->assertSame([3], $this->itemIds($result));
		}

		/** Slope allowlists are applied before SQL limits.
		 * @return void
		 */
		public function testSlopeItemsFilterFillsLimit(): void {
			$this->insertLink(1, 2, 2, 0.8);
			$this->insertLink(1, 3, 2, 0.2);
			$this->assertSame(3, $this->slope(1, new ArrayEligibilityProvider([3]), 1)[0]->productId);
		}

		// =========================================================================
		// predict (single member or visitor product)
		// =========================================================================

		public function testMemberPredictionReturnsNullWithNoData(): void {
			$this->assertNull($this->slopeOne->predict(Subject::member(1), 99));
		}

		public function testMemberPredictionReturnsPredictedRating(): void {
			// Member rated product 2 at 0.8; the pair is stored in both directions with opposite diff_slope
			// predicted = 0.8 * 1 + 0.1 = 0.9
			$this->insertRating(1, 2, 0.8);
			$this->insertLink(1, 2, 1, -0.1);
			$this->insertLink(2, 1, 1, 0.1);
			$result = $this->slopeOne->predict(Subject::member(1), 1);
			$this->assertNotNull($result);
			$this->assertEqualsWithDelta(0.9, $result->score, 0.0001);
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
			$all = $this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings());
			$this->assertCount(1, $all);
			$this->assertSame(20, $all[0]->productId);
			$this->assertEqualsWithDelta($all[0]->score,
				$this->slopeOne->predict(Subject::member(1), 20)->score, 0.00001);
		}

		public function testMemberPredictionClampsToOne(): void {
			$this->insertRating(1, 2, 1.0);
			$this->insertLink(1, 2, 1, -0.5);
			$this->insertLink(2, 1, 1, 0.5);
			$result = $this->slopeOne->predict(Subject::member(1), 1);
			$this->assertLessThanOrEqual(1.0, $result->score);
		}

		public function testMemberPredictionClampsToZero(): void {
			$this->insertRating(1, 2, 0.0);
			$this->insertLink(1, 2, 1, 0.5);
			$this->insertLink(2, 1, 1, -0.5);
			$result = $this->slopeOne->predict(Subject::member(1), 1);
			$this->assertGreaterThanOrEqual(0.0, $result->score);
		}

		// =========================================================================
		// member predictions (list)
		// =========================================================================

		public function testMemberPredictionsReturnsEmptyWhenNoLinks(): void {
			$this->insertRating(1, 10, 0.8);
			$this->assertSame([], $this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings()));
		}

		public function testMemberPredictionsExcludesAlreadyRatedItems(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertRating(1, 20, 0.5);
			$this->insertLink(10, 20, 2, 0.1);
			$this->insertLink(10, 30, 2, 0.2);
			$productIds = $this->itemIds($this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings()));
			$this->assertNotContains(10, $productIds);
			$this->assertNotContains(20, $productIds);
		}

		public function testMemberPredictionsAreSortedDescending(): void {
			$this->insertRating(1, 10, 0.8);
			$this->insertLink(10, 20, 2, 0.1);
			$this->insertLink(10, 30, 2, -0.1);
			$ratings = array_map(fn($result) => $result->score, $this->slopeOne->candidates(Subject::member(1), null, 10, new SourceSettings()));

			for ($i = 1; $i < count($ratings); $i++) {
				$this->assertGreaterThanOrEqual($ratings[$i], $ratings[$i - 1]);
			}
		}

		// =========================================================================
		// Visitor methods
		// =========================================================================

		public function testVisitorLinksReturnsEmptyForEmptyContext(): void {
			$visitor = new VisitorContext($this->config);
			$this->assertSame([], $this->visitorLinks($visitor));
		}

		public function testVisitorLinksReturnsLinkedItems(): void {
			$this->insertLink(10, 20, 5);
			$this->insertLink(10, 30, 3);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$result = $this->visitorLinks($visitor);
			$this->assertEqualsCanonicalizing([20, 30], $this->itemIds($result));
		}

		public function testVisitorLinksExcludesAlreadyRatedProducts(): void {
			$this->insertLink(10, 20, 5);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(10, 0.9);
			$visitor->setRating(20, 0.5);
			$result = $this->itemIds($this->visitorLinks($visitor));
			$this->assertNotContains(20, $result);
		}

		public function testVisitorPredictionReturnsNullForEmptyContext(): void {
			$visitor = new VisitorContext($this->config);
			$this->assertNull($this->slopeOne->predict(Subject::visitor($visitor), 1));
		}

		public function testVisitorPredictionReturnsPredictedRating(): void {
			$this->insertLink(1, 2, 1, -0.1);
			$this->insertLink(2, 1, 1, 0.1);
			$visitor = new VisitorContext($this->config);
			$visitor->setRating(2, 0.8);
			$result = $this->slopeOne->predict(Subject::visitor($visitor), 1);
			$this->assertNotNull($result);
			$this->assertEqualsWithDelta(0.9, $result->score, 0.0001);
		}

		// =========================================================================
		// Category isolation
		// =========================================================================

		public function testLinkedItemsIsolatedByCategory(): void {
			$this->insertLink(1, 2, 5, 0.0, 1);
			$this->insertLink(1, 3, 5, 0.0, 2);
			$this->assertSame([2], $this->itemIds($this->linked(1, null, 10, 1)));
			$this->assertSame([3], $this->itemIds($this->linked(1, null, 10, 2)));
		}

		/**
		 * Product-subject item-links lookup with the source's default settings.
		 * @param int $product Product ID
		 * @param EligibilityProvider|null $eligibility Restricts results, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int|null $category Category override
		 * @return array<int, RecommendationResult>
		 */
		private function linked(int $product, ?EligibilityProvider $eligibility = null, int $limit = 10, ?int $category = null): array {
			return $this->itemLinks->candidates(Subject::product($product), $eligibility, $limit, new SourceSettings(), $category);
		}

		/**
		 * Product-subject Slope One lookup.
		 * @param int $product Product ID
		 * @param EligibilityProvider|null $eligibility Restricts results, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $minSupport Minimum co-occurrence count
		 * @return array<int, RecommendationResult>
		 */
		private function slope(int $product, ?EligibilityProvider $eligibility = null, int $limit = 10, int $minSupport = 1): array {
			return $this->slopeOne->candidates(Subject::product($product), $eligibility, $limit, new SourceSettings(minSupport: $minSupport));
		}
	}
