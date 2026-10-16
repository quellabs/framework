<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Sources\ItemLinksSource;
use Quellabs\Recommender\Sources\NewProductsSource;
use Quellabs\Recommender\Sources\SlopeOneSource;
use Quellabs\Recommender\Sources\TopRatedSource;
use Quellabs\Recommender\Internal\UserSimilarity;
use Quellabs\Recommender\Sources\UserSimilaritySource;
use Quellabs\Recommender\RecommendationEngine;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;
use Quellabs\Recommender\VisitorContext;

/** Subject support and candidate depth for the candidate sources. */
class CandidateSourceTest extends IntegrationTestCase {

    /** @return void */
    public function testBothSourcesSupportEverySubjectKind(): void {
        foreach ($this->sources() as $source) {
            foreach (SubjectKind::cases() as $kind) {
                $this->assertTrue($source->supports($kind), "{$kind->value} subjects are not supported.");
            }
        }
    }

    /** Seen products, including not-interested ones, never reach the candidates of a member or visitor.
     * @return void
     */
    public function testMemberAndVisitorCandidatesExcludeSeenProducts(): void {
        $this->insertRating(5, 1, 1.0);
        $this->insertRating(5, 30, 0.5);
        $this->insertLink(1, 20, 5);
        $this->insertLink(1, 30, 3);
        $this->insertLink(1, 40, 1);

        $visitor = new VisitorContext($this->config);
        $visitor->setRating(1, 1.0);
        $visitor->setRating(30, 0.5);

        foreach ($this->sources() as $source) {
            $this->assertSame([20, 40], $this->candidateIds($source->candidates(Subject::member(5), null, 0, new SourceSettings())));
            $this->assertSame([20, 40], $this->candidateIds($source->candidates(Subject::visitor($visitor), null, 0, new SourceSettings())));
        }
    }

    /** With eligibility, deeper candidates are checked until the limit is met, so an ineligible top candidate is skipped.
     * @return void
     */
    public function testEligibilityBackfillsUntilLimit(): void {
        $this->insertLink(10, 20, 5);
        $this->insertLink(10, 30, 3);
        $this->insertLink(10, 40, 1);
        $source = new ItemLinksSource($this->connection, $this->config);
        $eligible = new ArrayEligibilityProvider([30, 40]);
        $this->assertSame([30], $this->candidateIds($source->candidates(Subject::product(10), $eligible, 1, new SourceSettings())));
        $this->assertSame([30, 40], $this->candidateIds($source->candidates(Subject::product(10), $eligible, 2, new SourceSettings())));
        $this->assertSame([30, 40], $this->candidateIds($source->candidates(Subject::product(10), $eligible, 0, new SourceSettings())));
    }

    /** Member candidates backfill the same way: product 20 is ineligible, so the limit of one takes product 40.
     * @return void
     */
    public function testMemberEligibilityBackfillsUntilLimit(): void {
        $this->insertRating(5, 1, 1.0);
        $this->insertLink(1, 20, 5);
        $this->insertLink(1, 40, 1);
        $eligible = new ArrayEligibilityProvider([40]);

        foreach ($this->sources() as $source) {
            $this->assertSame([40], $this->candidateIds($source->candidates(Subject::member(5), $eligible, 1, new SourceSettings())));
            $this->assertSame([40], $this->candidateIds($source->candidates(Subject::member(5), $eligible, 0, new SourceSettings())));
        }
    }

    /** Predict one product for a member and a visitor, from the links of their genuine ratings.
     * @return void
     */
    public function testSlopeOnePredictsOneProductForMemberAndVisitor(): void {
        $this->insertRating(5, 1, 1.0);
        $this->insertLink(1, 20, 5);
        $visitor = new VisitorContext($this->config);
        $visitor->setRating(1, 1.0);
        $source = new SlopeOneSource($this->connection, $this->config);

        $member = $source->predict(Subject::member(5), 20);
        $this->assertSame(1.0, $member->score);
        $this->assertSame(5, $member->supportCount);
        $this->assertSame(20, $source->predict(Subject::visitor($visitor), 20)->productId);
        $this->assertNull($source->predict(Subject::member(5), 99));
    }

    /** Reasons are the subject's liked products that link to the product, scored by the link's liked count.
     * @return void
     */
    public function testItemLinksReasonsListLikedProductsLinkedToTheProduct(): void {
        $this->insertRating(5, 1, 1.0);
        $this->insertRating(5, 2, 0.1);
        $this->insertLink(40, 1, 3);
        $this->insertLink(40, 2, 9);
        $source = new ItemLinksSource($this->connection, $this->config);
        $visitor = new VisitorContext($this->config);
        $visitor->setRating(1, 1.0);

        foreach ([Subject::member(5), Subject::visitor($visitor)] as $subject) {
            $reasons = $source->reasons($subject, 40);
            $this->assertSame([1], $this->candidateIds($reasons));
            $this->assertSame(3.0, $reasons[0]->score);
        }
    }

    /** Scores cover only the products asked for, with no depth or eligibility applied.
     * @return void
     */
    public function testScoresCoverOnlyTheRequestedProducts(): void {
        $this->insertRating(5, 1, 1.0);
        $this->insertLink(1, 20, 5);
        $this->insertLink(1, 40, 1);

        foreach ($this->sources() as $source) {
            $this->assertSame([40], $this->candidateIds($source->scores(Subject::member(5), [40, 99], new SourceSettings())));
            $this->assertSame([], $this->candidateIds($source->scores(Subject::member(5), [], new SourceSettings())));
        }
    }

    /** Top-rated orders by average rating, skips seen products and rejects product subjects.
     * @return void
     */
    public function testTopRatedOrdersByAverageAndSkipsSeenProducts(): void {
        $this->insertRating(5, 1, 1.0);
        $this->insertRating(6, 20, 0.9);
        $this->insertRating(7, 20, 0.7);
        $this->insertRating(6, 40, 0.5);
        $this->insertRating(7, 40, 0.4);
        $source = new TopRatedSource($this->connection, $this->config);

        $this->assertSame([20, 40], $this->candidateIds($source->candidates(Subject::member(5), null, 0, new SourceSettings())));
        $this->assertSame([20], $this->candidateIds($source->candidates(Subject::member(5), null, 1, new SourceSettings())));
        $scored = $this->candidateIds($source->scores(Subject::member(5), [20, 40, 1], new SourceSettings()));
        sort($scored);
        $this->assertSame([20, 40], $scored);
        $this->assertFalse($source->supports(SubjectKind::Product));
        $this->expectException(\InvalidArgumentException::class);
        $source->candidates(Subject::product(1), null, 0, new SourceSettings());
    }

    /** New products keep list order, skip seen products, count seen list positions toward depth, and skip ineligible ones.
     * @return void
     */
    public function testNewProductsKeepListOrderAndCountSeenPositions(): void {
        $this->insertRating(5, 1, 1.0);
        $source = new NewProductsSource($this->connection, $this->config, [1, 30, 40]);

        $this->assertSame([30, 40], $this->candidateIds($source->candidates(Subject::member(5), null, 0, new SourceSettings())));
        $this->assertSame([30], $this->candidateIds($source->candidates(Subject::member(5), null, 2, new SourceSettings())));
        $this->assertSame([40], $this->candidateIds($source->candidates(Subject::member(5), new ArrayEligibilityProvider([40]), 1, new SourceSettings())));
        $this->assertSame([40], $this->candidateIds($source->scores(Subject::member(5), [40, 99], new SourceSettings())));
        $this->expectException(\InvalidArgumentException::class);
        $source->scores(Subject::product(1), [1], new SourceSettings());
    }

    /** User similarity answers only for members.
     * @return void
     */
    public function testUserSimilarityRejectsNonMemberSubjects(): void {
        $similarity = new UserSimilarity($this->connection, $this->config, new RecommendationEngine($this->connection, $this->config));
        $source = new UserSimilaritySource($this->connection, $this->config, $similarity);
        $this->assertTrue($source->supports(SubjectKind::Member));
        $this->assertFalse($source->supports(SubjectKind::Visitor));
        $this->assertFalse($source->supports(SubjectKind::Product));
        $this->expectException(\InvalidArgumentException::class);
        $source->candidates(Subject::product(1), null, 0, new SourceSettings());
    }

    /** Single-product lookups answer only for member and visitor subjects.
     * @return void
     */
    public function testSingleProductLookupsRejectProductSubjects(): void {
        $source = new SlopeOneSource($this->connection, $this->config);
        $this->expectException(\InvalidArgumentException::class);
        $source->predict(Subject::product(1), 2);
    }

    /**
     * Return both candidate sources, which share the link-table candidate code paths under test.
     * @return array<int, CandidateSource>
     */
    private function sources(): array {
        return [new ItemLinksSource($this->connection, $this->config), new SlopeOneSource($this->connection, $this->config)];
    }

    /**
     * Extract product IDs from candidate results, preserving order.
     * @param array<int, \Quellabs\Recommender\RecommendationResult> $results Candidate results
     * @return array<int, int>
     */
    private function candidateIds(array $results): array {
        return array_map(fn($result) => $result->productId, $results);
    }
}
