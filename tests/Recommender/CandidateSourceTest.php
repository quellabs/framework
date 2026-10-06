<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Internal\Links\ItemLinksSource;
use Quellabs\Recommender\Internal\SlopeOne\SlopeOneSource;
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

    /** One round filters only the top depth candidates, so a shallow depth misses eligible products below it.
     * @return void
     */
    public function testDepthBoundsOneRoundOfEligibility(): void {
        $this->insertLink(10, 20, 5);
        $this->insertLink(10, 30, 3);
        $this->insertLink(10, 40, 1);
        $source = new ItemLinksSource($this->connection, $this->config);
        $eligible = new ArrayEligibilityProvider([30, 40]);
        $this->assertSame([], $this->candidateIds($source->candidates(Subject::product(10), $eligible, 1, new SourceSettings())));
        $this->assertSame([30], $this->candidateIds($source->candidates(Subject::product(10), $eligible, 2, new SourceSettings())));
        $this->assertSame([30, 40], $this->candidateIds($source->candidates(Subject::product(10), $eligible, 0, new SourceSettings())));
    }

    /** Member depth bounds the same single round: at depth one only product 20 is checked, and it is not eligible.
     * @return void
     */
    public function testMemberDepthBoundsOneRoundOfEligibility(): void {
        $this->insertRating(5, 1, 1.0);
        $this->insertLink(1, 20, 5);
        $this->insertLink(1, 40, 1);
        $eligible = new ArrayEligibilityProvider([40]);

        foreach ($this->sources() as $source) {
            $this->assertSame([], $this->candidateIds($source->candidates(Subject::member(5), $eligible, 1, new SourceSettings())));
            $this->assertSame([40], $this->candidateIds($source->candidates(Subject::member(5), $eligible, 0, new SourceSettings())));
        }
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
