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
}
