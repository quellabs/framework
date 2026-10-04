<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\RecommendationReconciler;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\ReconciliationRequest;
use Quellabs\Recommender\VisitorContext;
use Quellabs\Recommender\ServiceProvider;
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
