<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\ItemRecommender;
use Quellabs\Recommender\MemberId;
use Quellabs\Recommender\ProductId;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;
use Quellabs\Recommender\Reconciliation\ReconciliationTuning;
use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\Reconciliation\VisitorReconciliationRequest;
use Quellabs\Recommender\Reconciliation\VisitorSource;
use Quellabs\Recommender\Statistics;
use Quellabs\Recommender\VisitorContext;

/**
 * Compares the output of every recommendation path on a fixed fixture with a recorded baseline.
 *
 * Run with RECOMMENDER_RECORD_PARITY=1 to rewrite Fixtures/parity-baseline.json. Commit the file only
 * when an output change is intended.
 */
class ParityBaselineTest extends IntegrationTestCase {
    private const BASELINE_PATH = __DIR__ . '/Fixtures/parity-baseline.json';

    /** Catalog products referenced by the fixture. */
    private const PRODUCTS = [10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 30, 31];

    /** Rating rows as [member, product, rating]. A rating of -1.0 is the not-interested sentinel. */
    private const RATINGS = [
        [1, 10, 0.9], [1, 11, 0.4], [1, 12, 0.8], [1, 13, 0.7],
        [2, 10, 0.8], [2, 14, 0.9], [2, 15, 0.3], [2, 16, -1.0],
        [3, 11, 0.7], [3, 12, 0.5], [3, 14, 0.6], [3, 17, 0.9], [3, 18, 0.2],
        [4, 13, 0.9], [4, 15, 0.8], [4, 17, 0.7], [4, 19, 0.6], [4, 20, 0.5], [4, 22, 0.4],
        [5, 10, 0.9],
    ];

    /** Directed link rows as [item1, item2, count, diffSlope]. */
    private const LINKS = [
        [10, 14, 5, 0.3], [14, 10, 5, -0.3],
        [10, 11, 4, -0.1], [11, 10, 4, 0.1],
        [10, 12, 3, 0.2],
        [10, 16, 2, 0.5],
        [11, 12, 6, 0.4], [12, 11, 6, -0.4],
        [11, 17, 3, -0.2],
        [12, 13, 4, 0.1],
        [12, 18, 2, 0.6],
        [13, 15, 5, -0.4],
        [13, 19, 2, 0.25],
        [14, 15, 4, 0.35],
        [14, 17, 3, 0.15],
        [15, 20, 2, -0.3],
        [16, 21, 3, 0.45],
        [17, 18, 4, 0.05],
        [17, 22, 2, 0.7],
        [19, 20, 3, 0.2],
        [20, 23, 2, -0.15],
        [21, 24, 5, 0.33],
        [22, 25, 4, 0.12],
        [23, 26, 3, 0.28],
        [26, 20, 2, 0.4],
        [30, 12, 6, 0.9], [12, 30, 6, -0.9],
        [30, 13, 4, -0.3], [13, 30, 4, 0.3],
        [31, 10, 3, -0.2], [10, 31, 3, 0.2],
    ];

    /** @return void */
    public function testRecommendationPathsMatchBaseline(): void {
        $this->seedFixture();
        $actual = $this->collectOutputs();

        if (getenv('RECOMMENDER_RECORD_PARITY') === '1') {
            file_put_contents(self::BASELINE_PATH,
                json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $this->markTestIncomplete('Baseline written to ' . self::BASELINE_PATH . '; rerun without RECOMMENDER_RECORD_PARITY.');
        }

        $expected = json_decode((string)file_get_contents(self::BASELINE_PATH), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys($expected), array_keys($actual), 'The set of recorded cases changed.');

        foreach ($expected as $case => $output) {
            $this->assertSame($output, $actual[$case], "Output drift in case {$case}.");
        }
    }

    /** @return void */
    private function seedFixture(): void {
        foreach (self::RATINGS as [$member, $product, $rating]) {
            $this->insertRating($member, $product, $rating);
        }

        foreach (self::LINKS as [$item1, $item2, $count, $diffSlope]) {
            $this->insertLink($item1, $item2, $count, $diffSlope);
        }
    }

    /**
     * Run every recommendation path and collect its normalized output, keyed by case name.
     * @return array<string, mixed>
     */
    private function collectOutputs(): array {
        $outputs = [];
        $items = new ItemRecommender($this->connection, $this->config);
        $stats = new Statistics($this->connection, $this->config);
        $reconciler = new RecommendationReconciler($this->connection, $this->config);

        // Item paths take null for eligibility-off; reconciliation requires a provider in both modes.
        foreach (['off' => null, 'on' => $this->restrictedProvider()] as $mode => $eligibility) {
            $outputs["item.linked.10.{$mode}"] = $this->normalize($items->linkedProducts(10, $eligibility, 3));
            $outputs["item.linked.11.{$mode}"] = $this->normalize($items->linkedProducts(11, $eligibility, 10));
            $outputs["item.slope.10.{$mode}"] = $this->normalize($items->slopeProducts(10, $eligibility, 10));
            $outputs["item.slope.14.{$mode}"] = $this->normalize($items->slopeProducts(14, $eligibility, 10, 2));

            $provider = $eligibility ?? $this->openProvider();
            $linksMember = new ReconciliationRequest($provider, [RecommendationSource::ItemLinks], 5, 'home');
            $linksVisitor = new VisitorReconciliationRequest($provider, [VisitorSource::ItemLinks], 5, 'home');
            $outputs["links.member.1.{$mode}"] = $this->normalize($reconciler->memberSlate(1, $linksMember));
            $outputs["links.member.5.short.{$mode}"] = $this->normalize($reconciler->memberSlate(5, $linksMember));
            $outputs["links.member.6.cold.{$mode}"] = $this->normalize($reconciler->memberSlate(6, $linksMember));
            $outputs["links.visitor.A.{$mode}"] = $this->normalize($reconciler->visitorSlate($this->visitorA(), $linksVisitor));
            $outputs["links.visitor.C.short.{$mode}"] = $this->normalize($reconciler->visitorSlate($this->visitorC(), $linksVisitor));
            $outputs["links.visitor.B.empty.{$mode}"] = $this->normalize($reconciler->visitorSlate($this->visitorB(), $linksVisitor));

            $outputs["item.member.predictions.1.{$mode}"] = $this->normalize($items->memberPredictions(1, $eligibility, 10));
            $outputs["item.member.predictions.1.support2.{$mode}"] = $this->normalize($items->memberPredictions(1, $eligibility, 10, 2));
            $outputs["item.visitor.predictions.A.{$mode}"] = $this->normalize($items->visitorPredictions($this->visitorA(), $eligibility, 10));
        }

        $outputs['item.member.prediction.1.30'] = $this->normalize(
            $items->memberPrediction(new MemberId(1), new ProductId(30)));
        $outputs['item.visitor.prediction.A.30'] = $this->normalize($items->visitorPrediction($this->visitorA(), 30));
        $outputs['item.member.reasons.1.14'] = $this->normalize(
            $items->memberReasons(new MemberId(1), new ProductId(14), 10));
        $outputs['item.visitor.reasons.A.11'] = $this->normalize($items->visitorReasons($this->visitorA(), 11, 10));

        $outputs['stats.top_rated.min2'] = $this->normalize($stats->topRatedProducts(10, 2));
        $outputs['stats.top_rated.min1'] = $this->normalize($stats->topRatedProducts(10, 1));
        $outputs['stats.most_rated'] = $this->normalize($stats->mostRatedProducts(10));

        foreach ($this->memberSourceSets() as $set => $sources) {
            foreach (['off' => $this->openProvider(), 'on' => $this->restrictedProvider()] as $mode => $provider) {
                $request = $this->memberRequest($provider, $sources);

                foreach ([1, 5, 6] as $member) {
                    $suffix = "member.{$member}.{$set}.{$mode}";
                    $outputs["slate.{$suffix}"] = $this->normalize($reconciler->memberSlate($member, $request));
                    $outputs["pool.{$suffix}"] = $this->normalize($reconciler->memberCandidatePool($member, $request));
                }
            }
        }

        foreach ($this->visitorSourceSets() as $set => $sources) {
            foreach (['off' => $this->openProvider(), 'on' => $this->restrictedProvider()] as $mode => $provider) {
                $request = $this->visitorRequest($provider, $sources);

                foreach (['A' => $this->visitorA(), 'C' => $this->visitorC(), 'B' => $this->visitorB()] as $label => $visitor) {
                    $suffix = "visitor.{$label}.{$set}.{$mode}";
                    $outputs["slate.{$suffix}"] = $this->normalize($reconciler->visitorSlate($visitor, $request));
                    $outputs["pool.{$suffix}"] = $this->normalize($reconciler->visitorCandidatePool($visitor, $request));
                }
            }
        }

        // Tight bounds force the backfill loop to stop before the eligible pool is exhausted.
        $tight = new ReconciliationTuning(maxCandidateDepth: 50, maxBackfillRounds: 1, maxEligibilityBatchSize: 5);
        $tightMember = new ReconciliationRequest($this->restrictedProvider(), $this->memberSourceSets()['all'],
            5, 'home', [33, 12, 27], [26, 31], null, 'parity', $tight);
        $outputs['slate.member.1.all.tight'] = $this->normalize($reconciler->memberSlate(1, $tightMember));

        $tightVisitor = new VisitorReconciliationRequest($this->restrictedProvider(), $this->visitorSourceSets()['all'],
            5, 'home', [33, 12, 27], [26, 31], null, 'parity', $tight);
        $outputs['slate.visitor.A.all.tight'] = $this->normalize($reconciler->visitorSlate($this->visitorA(), $tightVisitor));

        return $outputs;
    }

    /**
     * Member source sets: each source alone, then all of them together.
     * @return array<string, array<int, RecommendationSource>>
     */
    private function memberSourceSets(): array {
        return [
            'item_links' => [RecommendationSource::ItemLinks],
            'slope_one' => [RecommendationSource::SlopeOne],
            'user_similarity' => [RecommendationSource::UserSimilarity],
            'top_rated' => [RecommendationSource::TopRated],
            'new_products' => [RecommendationSource::NewProducts],
            'all' => [
                RecommendationSource::ItemLinks,
                RecommendationSource::SlopeOne,
                RecommendationSource::UserSimilarity,
                RecommendationSource::TopRated,
                RecommendationSource::NewProducts,
            ],
        ];
    }

    /**
     * Visitor source sets: each source alone, then all of them together.
     * @return array<string, array<int, VisitorSource>>
     */
    private function visitorSourceSets(): array {
        return [
            'item_links' => [VisitorSource::ItemLinks],
            'slope_one' => [VisitorSource::SlopeOne],
            'top_rated' => [VisitorSource::TopRated],
            'new_products' => [VisitorSource::NewProducts],
            'all' => [VisitorSource::ItemLinks, VisitorSource::SlopeOne, VisitorSource::TopRated, VisitorSource::NewProducts],
        ];
    }

    /**
     * Build a member reconciliation request with fixed placement, limit and suggestions.
     * @param EligibilityProvider $provider Catalog eligibility
     * @param array<int, RecommendationSource> $sources Enabled sources
     * @return ReconciliationRequest
     */
    private function memberRequest(EligibilityProvider $provider, array $sources): ReconciliationRequest {
        return new ReconciliationRequest($provider, $sources, 5, 'home', [33, 12, 27], [26, 31], null, 'parity');
    }

    /**
     * Build a visitor reconciliation request with fixed placement, limit and suggestions.
     * @param EligibilityProvider $provider Catalog eligibility
     * @param array<int, VisitorSource> $sources Enabled sources
     * @return VisitorReconciliationRequest
     */
    private function visitorRequest(EligibilityProvider $provider, array $sources): VisitorReconciliationRequest {
        return new VisitorReconciliationRequest($provider, $sources, 5, 'home', [33, 12, 27], [26, 31], null, 'parity');
    }

    /**
     * Eligibility that admits every catalog product.
     * @return ArrayEligibilityProvider
     */
    private function openProvider(): ArrayEligibilityProvider {
        return new ArrayEligibilityProvider(self::PRODUCTS);
    }

    /**
     * Eligibility that rejects every product divisible by three, forcing backfill past the top candidates.
     * @return ArrayEligibilityProvider
     */
    private function restrictedProvider(): ArrayEligibilityProvider {
        return new ArrayEligibilityProvider(array_values(array_filter(self::PRODUCTS, fn(int $id): bool => $id % 3 !== 0)));
    }

    /**
     * Visitor A has three ratings, one of them liked and linked from the reasons case.
     * @return VisitorContext
     */
    private function visitorA(): VisitorContext {
        $visitor = new VisitorContext($this->config);
        $visitor->setRating(10, 0.9);
        $visitor->setRating(12, 0.4);
        $visitor->setRating(14, 0.8);
        return $visitor;
    }

    /**
     * Visitor C has one rating, which triggers the cold-start path.
     * @return VisitorContext
     */
    private function visitorC(): VisitorContext {
        $visitor = new VisitorContext($this->config);
        $visitor->setRating(20, 0.9);
        return $visitor;
    }

    /**
     * Visitor B has no ratings.
     * @return VisitorContext
     */
    private function visitorB(): VisitorContext {
        return new VisitorContext($this->config);
    }

    /**
     * Convert result objects into JSON-safe arrays. Enums become their values, floats are rounded,
     * and the diagnostics field is excluded from the baseline.
     * @param mixed $value Value to normalize
     * @return mixed JSON-safe representation
     */
    private function normalize(mixed $value): mixed {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_float($value)) {
            return round($value, 9);
        }

        if (is_object($value)) {
            $fields = [];

            foreach (get_object_vars($value) as $name => $field) {
                if ($name !== 'diagnostics') {
                    $fields[$name] = $this->normalize($field);
                }
            }

            return $fields;
        }

        if (is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalize($item), $value);
        }

        return $value;
    }
}
