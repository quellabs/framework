<?php

namespace Quellabs\Recommender\Tests;

use PHPUnit\Framework\TestCase;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\RecommendationList;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;

/** Request, result, and in-memory eligibility contracts. */
class ReconciliationContractsTest extends TestCase {
    /** @return void */
    public function testArrayEligibilityMatchesOrderedIntersection(): void {
        $eligible = [3, 5, 5, 8];
        $candidates = [8, 1, 3, 9, 5];
        $provider = new ArrayEligibilityProvider($eligible);
        $this->assertSame(array_values(array_intersect($candidates, $eligible)),
            $provider->filterEligible($candidates));
    }

    /** @return void */
    public function testCandidateDeduplicationPreservesFirstOccurrence(): void {
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([]),
            [RecommendationSource::NewProducts], 1, 'home', [5, 3, 5, 8, 3], [8, 2, 8]);
        $this->assertSame([5, 3, 8], $request->newProductIds);
        $this->assertSame([8, 2], $request->additionalCandidateIds);
    }

    /** @return void */
    public function testContextKeyValidationMatchesDirectListFactory(): void {
        foreach (['', str_repeat('x', 129), "tenant\nother", "ténant"] as $key) {
            foreach ([
                fn() => new ReconciliationRequest(new ArrayEligibilityProvider([]),
                    [RecommendationSource::NewProducts], 1, 'home', contextKey: $key),
                fn() => RecommendationList::fromDisplayedItems(1, 'home', [], $key),
            ] as $factory) {
                try {
                    $factory();
                    $this->fail('Invalid context key was accepted.');
                } catch (\InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertNull((new ReconciliationRequest(new ArrayEligibilityProvider([]),
            [RecommendationSource::NewProducts], 1, 'home'))->contextKey);
        $this->assertSame('tenant-a', RecommendationList::fromDisplayedItems(1, 'home', [],
            'tenant-a')->contextKey);
    }

    /** @return void */
    public function testWithDiagnosticsTogglesTheFlagAndKeepsEveryOtherField(): void {
        $eligibility = new ArrayEligibilityProvider([1, 2]);
        $request = new ReconciliationRequest($eligibility, [RecommendationSource::NewProducts], 5, 'home',
            [5, 3], [8, 2], category: 9, contextKey: 'tenant-a');
        $this->assertFalse($request->diagnostics);
        $withDiagnostics = $request->withDiagnostics();
        $this->assertTrue($withDiagnostics->diagnostics);
        $this->assertSame($eligibility, $withDiagnostics->eligibility);
        $this->assertSame($request->sources, $withDiagnostics->sources);
        $this->assertSame($request->limit, $withDiagnostics->limit);
        $this->assertSame($request->placement, $withDiagnostics->placement);
        $this->assertSame($request->newProductIds, $withDiagnostics->newProductIds);
        $this->assertSame($request->additionalCandidateIds, $withDiagnostics->additionalCandidateIds);
        $this->assertSame($request->category, $withDiagnostics->category);
        $this->assertSame($request->contextKey, $withDiagnostics->contextKey);
        $this->assertSame($request->tuning, $withDiagnostics->tuning);
        $this->assertFalse($withDiagnostics->withDiagnostics(false)->diagnostics);
    }
}
