<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\Subject;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\ProductId;

use Quellabs\Recommender\MemberId;

use Quellabs\Recommender\ScoreKind;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;
use Quellabs\Recommender\Reconciliation\ReconciliationTuning;
use Quellabs\Recommender\VisitorContext;
use Quellabs\Recommender\Integration\ServiceProvider;
use Quellabs\Recommender\Evaluation\EvaluationRecorder;
use Quellabs\Recommender\Evaluation\EvaluationReport;
use Quellabs\Recommender\RecommendationList;
use Quellabs\Recommender\Reconciliation\ReconciledRecommendation;
use Quellabs\Recommender\Reconciliation\SourceEvidence;

/** MySQL coverage for bounded eligibility and source fusion. */
class ReconciliationTest extends IntegrationTestCase {
    /** @return void */
    public function testNewProductsAndLinksFuseOnlyAfterEligibility(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertLink(10, 20, 3);
        $this->insertLink(10, 30, 2);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20, 40]),
            [RecommendationSource::NewProducts, RecommendationSource::ItemLinks], 3, 'home', [40]);
        $reconciler = $this->reconciler();
        $list = $reconciler->slate(Subject::member(1), $request);
        $this->assertSame(ScoreKind::Ranked, $list->scoreKind);
        $this->assertNull($list->scorerId);
        $this->assertSame([20, 40], array_map(fn($item) => $item->productId, $list->items));
        $this->assertEqualsWithDelta(1 / 61, $list->items[0]->rankingScore, 0.0000001);
        $this->assertSame(1, $list->items[0]->evidence[0]->sourceRank);
    }

    /** A subject with no ratings gets only the top-rated source, whatever sources were requested. @return void */
    public function testColdMemberGetsOnlyTopRated(): void {
        $this->insertRating(2, 20, 0.9);
        $this->insertRating(3, 20, 0.8);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
            [RecommendationSource::NewProducts, RecommendationSource::ItemLinks, RecommendationSource::TopRated], 5, 'home', [30]);
        $list = $this->reconciler()->slate(Subject::member(1), $request);
        $this->assertSame([RecommendationSource::TopRated], $list->sources);
        $this->assertSame([20], array_map(fn($item) => $item->productId, $list->items));
    }

    /** Ratings below the configured minimum history make a subject cold, and the minimum counts only non-negative ratings. @return void */
    public function testMinimumHistoryDecidesColdStart(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(1, 11, -1.0);
        $this->insertLink(10, 20, 5);
        $this->insertRating(2, 20, 0.9);
        $request = fn(int $minHistory) => new ReconciliationRequest(new ArrayEligibilityProvider([20]),
            [RecommendationSource::ItemLinks], 5, 'home', tuning: new ReconciliationTuning(minHistory: $minHistory));
        $reconciler = $this->reconciler();
        $this->assertSame([RecommendationSource::ItemLinks], $reconciler->slate(Subject::member(1), $request(1))->sources);
        $this->assertSame([RecommendationSource::TopRated], $reconciler->slate(Subject::member(1), $request(2))->sources);
    }

    /** @return VisitorContext Visitor with one rating outside every candidate list */
    private function warmVisitor(): VisitorContext {
        $visitor = new VisitorContext($this->config);
        $visitor->setRating(999, 0.9);
        return $visitor;
    }

    /** @return void */
    public function testInvalidProviderResponseFailsTheWholeRequest(): void {
        $provider = new class implements EligibilityProvider {
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array { return [999]; }
        };
        $request = new ReconciliationRequest($provider, [RecommendationSource::NewProducts], 1, 'home', [20]);
        $this->expectException(\UnexpectedValueException::class);
        ($this->reconciler())
            ->slate(Subject::visitor($this->warmVisitor()), $request);
    }

    /** @return void */
    public function testProviderRejectsDuplicateAndReorderedResponses(): void {
        $this->insertRating(1, 999, 0.9); // Warm subject: cold subjects get only top-rated.
        foreach ([[20, 20], [30, 20], [20, 999]] as $response) {
            $provider = new class($response) implements EligibilityProvider {
                /** @param array<int, int> $response Invalid reply. */
                public function __construct(private array $response) {}
                /** @inheritDoc */
                public function filterEligible(array $candidateIds): array { return $this->response; }
            };
            try {
                $this->reconciler()->slate(Subject::member(1),
                    new ReconciliationRequest($provider, [RecommendationSource::NewProducts],
                        2, 'home', [20, 30]));
                $this->fail('Invalid provider reply was accepted.');
            } catch (\UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    /** @return void */
    public function testEligibilityChangesAreVisibleOnTheNextRequest(): void {
        $this->insertRating(1, 999, 0.9); // Warm subject: cold subjects get only top-rated.
        $provider = new class implements EligibilityProvider {
            /** @var array<int, int> */
            public array $allowed = [20];
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                return array_values(array_intersect($candidateIds, $this->allowed));
            }
        };
        $request = new ReconciliationRequest($provider, [RecommendationSource::NewProducts],
            1, 'home', [20, 30]);
        $reconciler = $this->reconciler();
        $this->assertSame(20, $reconciler->slate(Subject::member(1), $request)->items[0]->productId);
        $provider->allowed = [30];
        $this->assertSame(30, $reconciler->slate(Subject::member(1), $request)->items[0]->productId);
    }

    /** @return void */
    public function testRejectingProviderStopsAtConfiguredBackfillBounds(): void {
        $this->insertRating(1, 999, 0.9); // Warm subject: cold subjects get only top-rated.
        $provider = new class implements EligibilityProvider {
            public int $calls = 0;
            public int $largestBatch = 0;
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                $this->calls++;
                $this->largestBatch = max($this->largestBatch, count($candidateIds));
                return [];
            }
        };
        $request = new ReconciliationRequest($provider, [RecommendationSource::NewProducts],
            10, 'home', range(1, 120), tuning: new ReconciliationTuning(maxCandidateDepth: 100, maxEligibilityBatchSize: 25));
        $list = ($this->reconcilerWith(new RecommendationConfig(directLinks: false, directSlope: false, maxBackfillRounds: 1)))
            ->slate(Subject::member(1), $request);
        $this->assertSame([], $list->items);
        $this->assertSame(4, $provider->calls);
        $this->assertSame(25, $provider->largestBatch);
    }

    /** @return void */
    public function testDepthFeatureTracksBackfillEvenWhenSourceDoesNotNominateItem(): void {
        $this->insertRating(1, 10, 0.9);
        for ($id = 100; $id < 160; $id++) {
            $this->insertLink(10, $id, 200 - $id);
        }
        $provider = new ArrayEligibilityProvider([999]);
        $reconciler = $this->reconciler();
        $shallow = $reconciler->slate(Subject::member(1), new ReconciliationRequest($provider,
            [RecommendationSource::ItemLinks], 1, 'home', additionalCandidateIds: [999], diagnostics: true));
        $deep = $reconciler->slate(Subject::member(1), new ReconciliationRequest($provider,
            [RecommendationSource::ItemLinks], 2, 'home', additionalCandidateIds: [999],
            tuning: new ReconciliationTuning(maxCandidateDepth: 100), diagnostics: true));
        $this->assertSame(50, $shallow->items[0]->diagnostics->searchedDepths['item_links']);
        $this->assertSame(100, $deep->items[0]->diagnostics->searchedDepths['item_links']);
        $this->assertSame(0.0, $shallow->items[0]->diagnostics->featureSnapshot['item_links.present']);
        $this->assertSame(0.0, $deep->items[0]->diagnostics->featureSnapshot['item_links.present']);
        $this->assertEqualsWithDelta(log(50),
            $shallow->items[0]->diagnostics->featureSnapshot['item_links.log_depth_searched'], 1e-9);
        $this->assertEqualsWithDelta(log(100),
            $deep->items[0]->diagnostics->featureSnapshot['item_links.log_depth_searched'], 1e-9);
    }

    /** @return void */
    public function testExhaustedSourceDoesNotStopAnotherSourcesBackfill(): void {
        $this->insertRating(1, 10, 0.9);
        for ($id = 100; $id < 170; $id++) {
            $this->insertLink(10, $id, 200 - $id);
        }
        $provider = new class implements EligibilityProvider {
            /** @var array<int, int> */
            public array $received = [];
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                array_push($this->received, ...$candidateIds);
                return array_values(array_filter($candidateIds, fn($id) => $id === 160));
            }
        };
        $list = $this->reconciler()->slate(Subject::member(1),
            new ReconciliationRequest($provider,
                [RecommendationSource::NewProducts, RecommendationSource::ItemLinks],
                1, 'home', [200, 201], tuning: new ReconciliationTuning(maxCandidateDepth: 100), diagnostics: true));
        $this->assertSame(160, $list->items[0]->productId);
        $this->assertSame(50, $list->items[0]->diagnostics->searchedDepths['new_products']);
        $this->assertSame(100, $list->items[0]->diagnostics->searchedDepths['item_links']);
        $this->assertSame(1, $list->items[0]->evidence[0]->sourceRank);
        $this->assertCount(72, $provider->received);
        $this->assertCount(72, array_unique($provider->received));
    }

    /** @return void */
    public function testContextKeyValidationRejectsEmptyString(): void {
        $this->expectException(\InvalidArgumentException::class);
        new ReconciliationRequest(new ArrayEligibilityProvider([]), [RecommendationSource::NewProducts],
            1, 'home', contextKey: '');
    }

    /** @return void */
    public function testSourceOrderDoesNotChangeFusion(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertLink(10, 20, 3);
        $reconciler = $this->reconciler();
        $provider = new ArrayEligibilityProvider([20, 40]);
        $first = $reconciler->slate(Subject::member(1), new ReconciliationRequest($provider,
            [RecommendationSource::NewProducts, RecommendationSource::ItemLinks], 2, 'home', [40]));
        $second = $reconciler->slate(Subject::member(1), new ReconciliationRequest($provider,
            [RecommendationSource::ItemLinks, RecommendationSource::NewProducts], 2, 'home', [40]));
        $this->assertEquals($first, $second);
    }

    /** @return void */
    public function testProviderFailureDuringBackfillPropagates(): void {
        $this->insertRating(1, 10, 0.9);
        for ($id = 100; $id < 170; $id++) {
            $this->insertLink(10, $id, 200 - $id);
        }
        $provider = new class implements EligibilityProvider {
            private int $calls = 0;
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                if (++$this->calls > 1) {
                    throw new \RuntimeException('Catalog unavailable');
                }
                return [];
            }
        };
        $request = new ReconciliationRequest($provider, [RecommendationSource::ItemLinks],
            2, 'home', tuning: new ReconciliationTuning(maxCandidateDepth: 100));
        $this->expectException(\RuntimeException::class);
        $this->reconciler()->slate(Subject::member(1), $request);
    }

    /** @return void */
    public function testProviderFailureOnFirstCallPropagates(): void {
        $this->insertRating(1, 999, 0.9); // Warm subject: cold subjects get only top-rated.
        $provider = new class implements EligibilityProvider {
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                throw new \RuntimeException('Catalog unavailable');
            }
        };
        $this->expectExceptionMessage('Catalog unavailable');
        $this->reconciler()->slate(Subject::member(1),
            new ReconciliationRequest($provider, [RecommendationSource::NewProducts],
                1, 'home', [20]));
    }

    /** @return void */
    public function testSeenAndRejectedProductsNeverReachEligibilityProvider(): void {
        $this->insertRating(1, 10, 0.8);
        $this->insertRating(1, 11, -1.0);
        $provider = new class implements EligibilityProvider {
            /** @var array<int, int> */
            public array $received = [];
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                array_push($this->received, ...$candidateIds);
                return $candidateIds;
            }
        };
        $list = $this->reconciler()->slate(Subject::member(1),
            new ReconciliationRequest($provider, [RecommendationSource::NewProducts],
                1, 'home', [10, 11, 12]));
        $this->assertSame([12], $provider->received);
        $this->assertSame(12, $list->items[0]->productId);
    }

    /** @return void */
    public function testEmptySourceStillRecordsTheRequestedSearchDepth(): void {
        $this->insertRating(1, 999, 0.9); // Warm subject: cold subjects get only top-rated.
        $list = $this->reconciler()->slate(Subject::member(1),
            new ReconciliationRequest(new ArrayEligibilityProvider([20]),
                [RecommendationSource::NewProducts], 1, 'home', additionalCandidateIds: [20], diagnostics: true));
        $this->assertSame(50, $list->items[0]->diagnostics->searchedDepths['new_products']);
        $this->assertSame(0.0, $list->items[0]->diagnostics->featureSnapshot['new_products.present']);
        $this->assertEqualsWithDelta(log(50),
            $list->items[0]->diagnostics->featureSnapshot['new_products.log_depth_searched'], 1e-9);
    }

    /** @return void */
    public function testSlopeAndTopRatedRemainEligibleAndCategoryBound(): void {
        $this->insertRating(1, 10, 0.8);
        $this->insertLink(10, 20, 3, 0.3);
        $this->insertRating(2, 30, 0.9);
        $this->insertRating(3, 30, 0.7);
        $this->insertRating(2, 99, 1.0, 2);
        $this->insertRating(3, 99, 1.0, 2);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20, 30, 99]),
            [RecommendationSource::SlopeOne, RecommendationSource::TopRated], 3, 'home');
        $list = $this->reconciler()->slate(Subject::member(1), $request);
        $this->assertSame([20, 30], array_map(fn($item) => $item->productId, $list->items));
        $this->assertSame(3, $list->items[0]->evidence[0]->supportCount);
    }

    /** @return void */
    public function testMemberAndVisitorHaveEqualEvidenceForSharedSources(): void {
        $this->insertRating(1, 10, 0.8);
        $this->insertLink(10, 20, 3, 0.3);
        $this->insertRating(2, 20, 0.9);
        $this->insertRating(3, 20, 0.7);
        $visitor = new VisitorContext($this->config);
        $visitor->setRating(10, 0.8);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
            [RecommendationSource::ItemLinks, RecommendationSource::SlopeOne,
                RecommendationSource::TopRated, RecommendationSource::NewProducts],
            2, 'parity', [30]);
        $visitorRequest = new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
            [RecommendationSource::ItemLinks, RecommendationSource::SlopeOne, RecommendationSource::TopRated, RecommendationSource::NewProducts],
            2, 'parity', [30]);
        $reconciler = $this->reconciler();
        $this->assertEquals($reconciler->slate(Subject::member(1), $request)->items,
            $reconciler->slate(Subject::visitor($visitor), $visitorRequest)->items);
    }

    /** @return void */
    public function testCatalogProviderFiltersUnknownAndStaleCandidatesPerRequest(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertLink(10, 20, 3);
        $this->insertLink(10, 99, 3);
        $reconciler = $this->reconciler();
        $sources = [RecommendationSource::ItemLinks, RecommendationSource::NewProducts];
        $tenantA = $reconciler->slate(Subject::member(1),
            new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]), $sources,
                3, 'home', [30, 999], contextKey: 'tenant-a'));
        $tenantB = $reconciler->slate(Subject::member(1),
            new ReconciliationRequest(new ArrayEligibilityProvider([30]), $sources,
                3, 'home', [30, 999], contextKey: 'tenant-b'));
        $this->assertSame([20, 30], array_map(fn($item) => $item->productId, $tenantA->items));
        $this->assertSame([30], array_map(fn($item) => $item->productId, $tenantB->items));
    }

    /** @return void */
    public function testDerivedSignalsDependOnRowsEvenWhenIncrementalUpdatesAreDisabled(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(2, 20, 0.9);
        $this->insertRating(3, 20, 0.8);
        $reconciler = $this->reconciler();
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
            [RecommendationSource::ItemLinks, RecommendationSource::SlopeOne,
                RecommendationSource::NewProducts], 2, 'derived_modes', [30]);
        $before = $reconciler->slate(Subject::member(1), $request);
        $this->assertSame([30], array_map(fn($item) => $item->productId, $before->items));
        $this->insertLink(10, 20, 3, 0.3);
        $after = $reconciler->slate(Subject::member(1), $request);
        $this->assertSame([20, 30], array_map(fn($item) => $item->productId, $after->items));
        $this->assertSame([RecommendationSource::ItemLinks, RecommendationSource::SlopeOne],
            array_map(fn($evidence) => $evidence->source, $after->items[0]->evidence));
    }

    /** @return void */
    public function testTopRatedTieBreaksByItemId(): void {
        foreach ([20, 30] as $id) {
            $this->insertRating($id, $id, 0.8);
            $this->insertRating($id + 100, $id, 0.8);
        }
        $list = $this->reconciler()->slate(Subject::member(1),
            new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
                [RecommendationSource::TopRated], 2, 'tie_test'));
        $this->assertSame([20, 30], array_map(fn($item) => $item->productId, $list->items));
        $this->assertSame([1, 2], array_map(fn($item) => $item->evidence[0]->sourceRank, $list->items));
    }

    /** @return void */
    public function testTopRatedQueryCountAndLimitStayBoundedAsRatingsGrow(): void {
        $reconciler = $this->reconciler();
        $request = new ReconciliationRequest(new ArrayEligibilityProvider(range(100, 399)),
            [RecommendationSource::TopRated], 10, 'query_bounds');
        $counts = [];
        foreach ([[100, 149], [150, 399]] as [$start, $end]) {
            for ($id = $start; $id <= $end; $id++) {
                $this->insertRating($id, $id, 0.8);
                $this->insertRating($id + 1000, $id, 0.8);
            }
            $logger = new SqlCaptureLogger();
            $driver = $this->connection->getDriver();
            $previousLogger = $driver->getLogger();
            $driver->setLogger($logger);
            try {
                $list = $reconciler->slate(Subject::member(1), $request);
            } finally {
                $driver->disableQueryLogging();
                if ($previousLogger !== null) {
                    $driver->setLogger($previousLogger);
                }
            }
            $this->assertCount(10, $list->items);
            $topRatedQueries = array_values(array_filter($logger->queries,
                fn($query) => str_contains($query, 'AVG(r.rating) AS score')));
            $this->assertCount(1, $topRatedQueries);
            $this->assertStringContainsString('LIMIT 50', $topRatedQueries[0]);
            $counts[] = count($logger->queries);
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->assertLessThanOrEqual(8, $counts[1]);
    }

    /** A request reads the subject's ratings once, however many rounds and sources it runs.
     * @return void
     */
    public function testSubjectRatingsAreReadOncePerRequest(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(2, 10, 0.9);
        $this->insertRating(2, 40, 0.8);
        $this->insertLink(10, 20, 10, 0.1);
        $logger = new SqlCaptureLogger();
        $driver = $this->connection->getDriver();
        $previousLogger = $driver->getLogger();
        $driver->setLogger($logger);
        try {
            $this->reconciler()->slate(Subject::member(1), new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
                [RecommendationSource::ItemLinks, RecommendationSource::SlopeOne, RecommendationSource::TopRated,
                    RecommendationSource::NewProducts, RecommendationSource::UserSimilarity],
                5, 'home', [30]));
        } finally {
            $driver->disableQueryLogging();
            if ($previousLogger !== null) {
                $driver->setLogger($previousLogger);
            }
        }
        $seenReads = array_values(array_filter($logger->queries,
            fn($query) => preg_match('/SELECT\s+product_id,\s+rating\s+FROM vogoo_ratings/', $query) === 1));
        $this->assertCount(1, $seenReads);
    }

    /** @return void */
    public function testUserSimilarityUsesNeighboursForMembersOnly(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(2, 10, 0.9);
        $this->insertRating(2, 20, 0.9);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20]),
            [RecommendationSource::UserSimilarity], 1, 'home');
        $reconciler = $this->reconciler();
        $list = $reconciler->slate(Subject::member(1), $request);
        $this->assertSame(20, $list->items[0]->productId);
        $this->assertGreaterThan(0.0, $list->items[0]->evidence[0]->rawScore);
    }

    /** @return void */
    public function testEligibilityBatchPayloadIsCapped(): void {
        $provider = new class implements EligibilityProvider {
            public int $maxSeen = 0;
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                $this->maxSeen = max($this->maxSeen, count($candidateIds));
                return $candidateIds;
            }
        };
        $request = new ReconciliationRequest($provider, [RecommendationSource::NewProducts],
            10, 'home', range(1, 80), tuning: new ReconciliationTuning(maxEligibilityBatchSize: 7));
        $list = ($this->reconciler())
            ->slate(Subject::visitor($this->warmVisitor()), $request);
        $this->assertCount(10, $list->items);
        $this->assertSame(7, $provider->maxSeen);
    }

    /** @return void */
    public function testOnlyEligibleSurvivorGetsFirstSourceRank(): void {
        $this->insertRating(1, 10, 0.9);
        for ($id = 100; $id < 150; $id++) {
            $this->insertLink(10, $id, 200 - $id);
        }
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([149]),
            [RecommendationSource::ItemLinks], 1, 'home');
        $list = $this->reconciler()->slate(Subject::member(1), $request);
        $this->assertSame(149, $list->items[0]->productId);
        $this->assertSame(1, $list->items[0]->evidence[0]->sourceRank);
        $this->assertEqualsWithDelta(1 / 61, $list->items[0]->rankingScore, 0.0000001);
    }

    /** @return void */
    public function testChangedSourcePrefixFailsRequestDuringBackfill(): void {
        $this->insertRating(1, 10, 0.9);
        for ($id = 100; $id < 170; $id++) {
            $this->insertLink(10, $id, 200 - $id);
        }
        $provider = new class($this->connection) implements EligibilityProvider {
            private bool $changed = false;
            /** @param \Cake\Database\Connection $connection Ratings database. */
            public function __construct(private \Cake\Database\Connection $connection) {}
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                if (!$this->changed) {
                    $this->connection->execute('UPDATE vogoo_links SET liked_count = 1000
                        WHERE item_id1 = 10 AND item_id2 = 160');
                    $this->changed = true;
                }
                return [];
            }
        };
        $request = new ReconciliationRequest($provider, [RecommendationSource::ItemLinks],
            2, 'home', tuning: new ReconciliationTuning(maxCandidateDepth: 100));
        $this->expectExceptionMessage('Candidate order changed');
        $this->reconciler()->slate(Subject::member(1), $request);
    }

    /** @return void */
    public function testCanvasProviderRegistersNewServices(): void {
        $provider = new ServiceProvider();
        foreach ([RecommendationReconciler::class => [$this->connection, $this->config],
            EvaluationRecorder::class => [$this->connection],
            EvaluationReport::class => [$this->connection]] as $class => $dependencies) {
            $this->assertTrue($provider->supports($class, []));
            $this->assertInstanceOf($class, $provider->createInstance($class, $dependencies, []));
        }
    }

    /** @return void */
    public function testComposerDiscoveryPointsToTheMovedCanvasProvider(): void {
        $package = json_decode((string)file_get_contents(
            __DIR__ . '/../../packages/recommender/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $provider = $package['extra']['discover']['di']['provider'];
        $this->assertSame(ServiceProvider::class, $provider);
        $this->assertTrue(class_exists($provider));
    }

    /** @return void */
    public function testProviderParsesStringBooleanConfigValues(): void {
        $provider = new ServiceProvider();
        $provider->setConfig(['direct_links' => 'false', 'direct_slope' => '1']);
        $config = $provider->createInstance(\Quellabs\Recommender\Config\RecommendationConfig::class, [], []);
        $this->assertFalse($config->isDirectLinks());
        $this->assertTrue($config->isDirectSlope());
    }

    /** @return void */
    public function testSculptProviderParsesStringBooleanConfigValues(): void {
        $falsy = new \Quellabs\Recommender\Sculpt\RecommenderProvider();
        $falsy->setConfig(['direct_links' => 'false', 'direct_slope' => '0']);
        $this->assertFalse($falsy->getRecommendationConfig()->isDirectLinks());
        $this->assertFalse($falsy->getRecommendationConfig()->isDirectSlope());

        // getRecommendationConfig() caches its result, so each case needs its own provider
        $unrecognized = new \Quellabs\Recommender\Sculpt\RecommenderProvider();
        $unrecognized->setConfig(['direct_links' => 'true', 'direct_slope' => 'maybe']);
        $this->assertTrue($unrecognized->getRecommendationConfig()->isDirectLinks());
        $this->assertTrue($unrecognized->getRecommendationConfig()->isDirectSlope());
    }

    /** @return void */
    public function testDirectListSelectionPreservesEvidenceAndRejectsDuplicates(): void {
        $first = new ReconciledRecommendation(10, null,
            [new SourceEvidence(RecommendationSource::ItemLinks, 2.0, 1)]);
        $second = new ReconciledRecommendation(20, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $list = RecommendationList::fromDisplayedItems(1, 'home', [$first, $second], 'tenant-a');
        $selected = $list->selectDisplayedProducts([20, 10]);
        $this->assertSame([$second, $first], $selected->items);
        $this->assertSame('tenant-a', $selected->contextKey);
        $this->expectException(\InvalidArgumentException::class);
        RecommendationList::fromDisplayedItems(1, 'home', [$first, $first]);
    }

    /** @return void */
    public function testDisplayedCandidateKeepsSignalOutsideSourceTopSlice(): void {
        for ($id = 100; $id <= 150; $id++) {
            $rating = 1.0 - ($id - 100) / 100;
            $this->insertRating($id, $id, $rating);
            $this->insertRating($id + 1000, $id, $rating);
        }
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([150]),
            [RecommendationSource::TopRated], 1, 'home', additionalCandidateIds: [150], diagnostics: true);
        $list = $this->reconciler()->slate(Subject::member(1), $request);
        $this->assertSame(150, $list->items[0]->productId);
        $this->assertSame(0.0, $list->items[0]->rankingScore);
        $this->assertSame(0.0, $list->items[0]->diagnostics->featureSnapshot['top_rated.present']);
        $this->assertSame(50, $list->items[0]->diagnostics->searchedDepths['top_rated']);
        $this->assertCount(1, $list->items[0]->evidence);
        $this->assertNull($list->items[0]->evidence[0]->sourceRank);
        $this->assertEqualsWithDelta(0.5, $list->items[0]->evidence[0]->rawScore, 0.00001);
    }

    /** @return void */
    public function testOutsideSliceEvidenceIsAuditedForOtherSources(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(2, 10, 0.9);
        for ($id = 100; $id <= 150; $id++) {
            $this->insertLink(10, $id, 200 - $id, 200 - $id);
            $this->insertRating(2, $id, 1.0 - ($id - 100) / 200);
        }
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([150]),
            [RecommendationSource::NewProducts, RecommendationSource::ItemLinks,
                RecommendationSource::SlopeOne, RecommendationSource::UserSimilarity],
            1, 'home', range(100, 150), additionalCandidateIds: [150], diagnostics: true);
        $list = $this->reconciler()->slate(Subject::member(1), $request);
        $item = $list->items[0];
        $this->assertSame(150, $item->productId);
        $this->assertSame(0.0, $item->rankingScore);
        $this->assertCount(4, $item->evidence);
        $sources = array_map(fn($evidence) => $evidence->source->value, $item->evidence);
        sort($sources);
        $this->assertSame(['item_links', 'new_products', 'slope_one', 'user_similarity'], $sources);
        foreach ($item->evidence as $evidence) {
            $this->assertNull($evidence->sourceRank);
            $this->assertSame(0.0, $item->diagnostics->featureSnapshot[$evidence->source->value . '.present']);
        }
    }

    /** Diagnostics are attached to items only when the request asks for them.
     * @return void
     */
    public function testDiagnosticsAreAttachedOnlyWhenRequested(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertLink(10, 20, 10);
        $provider = new ArrayEligibilityProvider([20]);
        $plain = $this->reconciler()->slate(Subject::member(1),
            new ReconciliationRequest($provider, [RecommendationSource::ItemLinks], 1, 'home'));
        $explained = $this->reconciler()->slate(Subject::member(1),
            new ReconciliationRequest($provider, [RecommendationSource::ItemLinks], 1, 'home', diagnostics: true));
        $this->assertNull($plain->items[0]->diagnostics);
        $this->assertSame(['item_links' => 50], $explained->items[0]->diagnostics?->searchedDepths);
        $this->assertSame($plain->items[0]->rankingScore, $explained->items[0]->rankingScore);
    }

    /** @return void */
    public function testVisitorCannotRequestUserSimilarity(): void {
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20]), [RecommendationSource::UserSimilarity], 1, 'home');

        $this->expectException(\InvalidArgumentException::class);
        $this->reconciler()->slate(Subject::visitor($this->warmVisitor()), $request);
    }
}
