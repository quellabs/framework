<?php

namespace Quellabs\Recommender\Tests;

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
}
