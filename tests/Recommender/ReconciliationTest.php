<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\RecommendationReconciler;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\ReconciliationRequest;
use Quellabs\Recommender\VisitorContext;
use Quellabs\Recommender\Integration\ServiceProvider;
use Quellabs\Recommender\EvaluationRecorder;
use Quellabs\Recommender\EvaluationReport;
use Quellabs\Recommender\RecommendationList;
use Quellabs\Recommender\ReconciledRecommendation;
use Quellabs\Recommender\SourceEvidence;

/** MySQL coverage for bounded eligibility and source fusion. */
class ReconciliationTest extends IntegrationTestCase {
    /** @return void */
    public function testNewProductsAndLinksFuseOnlyAfterEligibility(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertLink(10, 20, 3);
        $this->insertLink(10, 30, 2);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20, 40]),
            [RecommendationSource::NewProducts, RecommendationSource::ItemLinks], 3, 'home', [40]);
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $list = $reconciler->recommendMember(1, $request);
        $this->assertSame('rank_fusion', $list->scoreKind);
        $this->assertSame([20, 40], array_map(fn($item) => $item->itemId, $list->items));
        $this->assertEqualsWithDelta(1 / 61, $list->items[0]->rankingScore, 0.0000001);
        $this->assertSame(1, $list->items[0]->evidence[0]->sourceRank);
    }

    /** @return void */
    public function testInvalidProviderResponseFailsTheWholeRequest(): void {
        $provider = new class implements EligibilityProvider {
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array { return [999]; }
        };
        $request = new ReconciliationRequest($provider, [RecommendationSource::NewProducts], 1, 'home', [20]);
        $this->expectException(\UnexpectedValueException::class);
        (new RecommendationReconciler($this->connection, $this->config))
            ->recommendVisitor(new VisitorContext($this->config), $request);
    }

    /** @return void */
    public function testProviderRejectsDuplicateAndReorderedResponses(): void {
        foreach ([[20, 20], [30, 20], [20, 999]] as $response) {
            $provider = new class($response) implements EligibilityProvider {
                /** @param array<int, int> $response Invalid reply. */
                public function __construct(private array $response) {}
                /** @inheritDoc */
                public function filterEligible(array $candidateIds): array { return $this->response; }
            };
            try {
                (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1,
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
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $this->assertSame(20, $reconciler->recommendMember(1, $request)->items[0]->itemId);
        $provider->allowed = [30];
        $this->assertSame(30, $reconciler->recommendMember(1, $request)->items[0]->itemId);
    }

    /** @return void */
    public function testRejectingProviderStopsAtConfiguredBackfillBounds(): void {
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
            10, 'home', range(1, 120), maxCandidateDepth: 100,
            maxBackfillRounds: 1, maxEligibilityBatchSize: 25);
        $list = (new RecommendationReconciler($this->connection, $this->config))
            ->recommendMember(1, $request);
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
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $shallow = $reconciler->recommendMember(1, new ReconciliationRequest($provider,
            [RecommendationSource::ItemLinks], 1, 'home', additionalCandidateIds: [999]));
        $deep = $reconciler->recommendMember(1, new ReconciliationRequest($provider,
            [RecommendationSource::ItemLinks], 2, 'home', additionalCandidateIds: [999],
            maxCandidateDepth: 100));
        $this->assertSame(50, $shallow->items[0]->searchedDepths['item_links']);
        $this->assertSame(100, $deep->items[0]->searchedDepths['item_links']);
        $this->assertSame(0.0, $shallow->items[0]->featureSnapshot['item_links.present']);
        $this->assertSame(0.0, $deep->items[0]->featureSnapshot['item_links.present']);
        $this->assertEqualsWithDelta(log(50),
            $shallow->items[0]->featureSnapshot['item_links.log_depth_searched'], 1e-9);
        $this->assertEqualsWithDelta(log(100),
            $deep->items[0]->featureSnapshot['item_links.log_depth_searched'], 1e-9);
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
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1,
            new ReconciliationRequest($provider,
                [RecommendationSource::NewProducts, RecommendationSource::ItemLinks],
                1, 'home', [200, 201], maxCandidateDepth: 100));
        $this->assertSame(160, $list->items[0]->itemId);
        $this->assertSame(50, $list->items[0]->searchedDepths['new_products']);
        $this->assertSame(100, $list->items[0]->searchedDepths['item_links']);
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
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $provider = new ArrayEligibilityProvider([20, 40]);
        $first = $reconciler->recommendMember(1, new ReconciliationRequest($provider,
            [RecommendationSource::NewProducts, RecommendationSource::ItemLinks], 2, 'home', [40]));
        $second = $reconciler->recommendMember(1, new ReconciliationRequest($provider,
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
            2, 'home', maxCandidateDepth: 100);
        $this->expectException(\RuntimeException::class);
        (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1, $request);
    }

    /** @return void */
    public function testProviderFailureOnFirstCallPropagates(): void {
        $provider = new class implements EligibilityProvider {
            /** @inheritDoc */
            public function filterEligible(array $candidateIds): array {
                throw new \RuntimeException('Catalog unavailable');
            }
        };
        $this->expectExceptionMessage('Catalog unavailable');
        (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1,
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
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1,
            new ReconciliationRequest($provider, [RecommendationSource::NewProducts],
                1, 'home', [10, 11, 12]));
        $this->assertSame([12], $provider->received);
        $this->assertSame(12, $list->items[0]->itemId);
    }

    /** @return void */
    public function testEmptySourceStillRecordsTheRequestedSearchDepth(): void {
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1,
            new ReconciliationRequest(new ArrayEligibilityProvider([20]),
                [RecommendationSource::NewProducts], 1, 'home', additionalCandidateIds: [20]));
        $this->assertSame(50, $list->items[0]->searchedDepths['new_products']);
        $this->assertSame(0.0, $list->items[0]->featureSnapshot['new_products.present']);
        $this->assertEqualsWithDelta(log(50),
            $list->items[0]->featureSnapshot['new_products.log_depth_searched'], 1e-9);
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
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1, $request);
        $this->assertSame([20, 30], array_map(fn($item) => $item->itemId, $list->items));
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
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $this->assertEquals($reconciler->recommendMember(1, $request)->items,
            $reconciler->recommendVisitor($visitor, $request)->items);
    }

    /** @return void */
    public function testCatalogProviderFiltersUnknownAndStaleCandidatesPerRequest(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertLink(10, 20, 3);
        $this->insertLink(10, 99, 3);
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $sources = [RecommendationSource::ItemLinks, RecommendationSource::NewProducts];
        $tenantA = $reconciler->recommendMember(1,
            new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]), $sources,
                3, 'home', [30, 999], contextKey: 'tenant-a'));
        $tenantB = $reconciler->recommendMember(1,
            new ReconciliationRequest(new ArrayEligibilityProvider([30]), $sources,
                3, 'home', [30, 999], contextKey: 'tenant-b'));
        $this->assertSame([20, 30], array_map(fn($item) => $item->itemId, $tenantA->items));
        $this->assertSame([30], array_map(fn($item) => $item->itemId, $tenantB->items));
    }

    /** @return void */
    public function testDerivedSignalsDependOnRowsEvenWhenIncrementalUpdatesAreDisabled(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(2, 20, 0.9);
        $this->insertRating(3, 20, 0.8);
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
            [RecommendationSource::ItemLinks, RecommendationSource::SlopeOne,
                RecommendationSource::NewProducts], 2, 'derived_modes', [30]);
        $before = $reconciler->recommendMember(1, $request);
        $this->assertSame([30], array_map(fn($item) => $item->itemId, $before->items));
        $this->insertLink(10, 20, 3, 0.3);
        $after = $reconciler->recommendMember(1, $request);
        $this->assertSame([20, 30], array_map(fn($item) => $item->itemId, $after->items));
        $this->assertSame([RecommendationSource::ItemLinks, RecommendationSource::SlopeOne],
            array_map(fn($evidence) => $evidence->source, $after->items[0]->evidence));
    }

    /** @return void */
    public function testTopRatedTieBreaksByItemId(): void {
        foreach ([20, 30] as $id) {
            $this->insertRating($id, $id, 0.8);
            $this->insertRating($id + 100, $id, 0.8);
        }
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1,
            new ReconciliationRequest(new ArrayEligibilityProvider([20, 30]),
                [RecommendationSource::TopRated], 2, 'tie_test'));
        $this->assertSame([20, 30], array_map(fn($item) => $item->itemId, $list->items));
        $this->assertSame([1, 2], array_map(fn($item) => $item->evidence[0]->sourceRank, $list->items));
    }

    /** @return void */
    public function testTopRatedQueryCountAndLimitStayBoundedAsRatingsGrow(): void {
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
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
                $list = $reconciler->recommendMember(1, $request);
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

    /** @return void */
    public function testUserSimilarityUsesNeighboursForMembersOnly(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(2, 10, 0.9);
        $this->insertRating(2, 20, 0.9);
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([20]),
            [RecommendationSource::UserSimilarity], 1, 'home');
        $reconciler = new RecommendationReconciler($this->connection, $this->config);
        $list = $reconciler->recommendMember(1, $request);
        $this->assertSame(20, $list->items[0]->itemId);
        $this->assertGreaterThan(0.0, $list->items[0]->evidence[0]->rawScore);
        $this->expectException(\InvalidArgumentException::class);
        $reconciler->recommendVisitor(new VisitorContext($this->config), $request);
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
            10, 'home', range(1, 80), maxEligibilityBatchSize: 7);
        $list = (new RecommendationReconciler($this->connection, $this->config))
            ->recommendVisitor(new VisitorContext($this->config), $request);
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
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1, $request);
        $this->assertSame(149, $list->items[0]->itemId);
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
            2, 'home', maxCandidateDepth: 100);
        $this->expectExceptionMessage('Candidate order changed');
        (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1, $request);
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
    public function testDirectListSelectionPreservesEvidenceAndRejectsDuplicates(): void {
        $first = new ReconciledRecommendation(10, null,
            [new SourceEvidence(RecommendationSource::ItemLinks, 2.0, 1)]);
        $second = new ReconciledRecommendation(20, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $list = RecommendationList::fromDisplayedItems(1, 'home', [$first, $second], 'tenant-a');
        $selected = $list->selectDisplayedIds([20, 10]);
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
            [RecommendationSource::TopRated], 1, 'home', additionalCandidateIds: [150]);
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1, $request);
        $this->assertSame(150, $list->items[0]->itemId);
        $this->assertSame(0.0, $list->items[0]->rankingScore);
        $this->assertSame(0.0, $list->items[0]->featureSnapshot['top_rated.present']);
        $this->assertSame(50, $list->items[0]->searchedDepths['top_rated']);
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
            1, 'home', range(100, 150), additionalCandidateIds: [150]);
        $list = (new RecommendationReconciler($this->connection, $this->config))->recommendMember(1, $request);
        $item = $list->items[0];
        $this->assertSame(150, $item->itemId);
        $this->assertSame(0.0, $item->rankingScore);
        $this->assertCount(4, $item->evidence);
        $sources = array_map(fn($evidence) => $evidence->source->value, $item->evidence);
        sort($sources);
        $this->assertSame(['item_links', 'new_products', 'slope_one', 'user_similarity'], $sources);
        foreach ($item->evidence as $evidence) {
            $this->assertNull($evidence->sourceRank);
            $this->assertSame(0.0, $item->featureSnapshot[$evidence->source->value . '.present']);
        }
    }
}
