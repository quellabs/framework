<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\Internal\Links\ItemLinksSource;
use Quellabs\Recommender\Internal\SlopeOne\SlopeOneSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;
use Quellabs\Recommender\VisitorContext;

/** Subject support for the candidate sources. */
class CandidateSourceTest extends IntegrationTestCase {

    /** @return void */
    public function testBothSourcesSupportOnlyProductSubjects(): void {
        foreach ([new ItemLinksSource($this->connection, $this->config), new SlopeOneSource($this->connection, $this->config)] as $source) {
            $this->assertTrue($source->supports(SubjectKind::Product));
            $this->assertFalse($source->supports(SubjectKind::Member));
            $this->assertFalse($source->supports(SubjectKind::Visitor));
        }
    }

    /** @return void */
    public function testUnsupportedSubjectsAreRejected(): void {
        $subjects = [Subject::member(1), Subject::visitor(new VisitorContext($this->config))];

        foreach ([new ItemLinksSource($this->connection, $this->config), new SlopeOneSource($this->connection, $this->config)] as $source) {
            foreach ($subjects as $subject) {
                try {
                    $source->candidates($subject, null, 5, new SourceSettings());
                    $this->fail("{$subject->kind->value} subject was accepted.");
                } catch (\InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
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

    /**
     * Extract product IDs from candidate results, preserving order.
     * @param array<int, \Quellabs\Recommender\RecommendationResult> $results Candidate results
     * @return array<int, int>
     */
    private function candidateIds(array $results): array {
        return array_map(fn($result) => $result->productId, $results);
    }
}