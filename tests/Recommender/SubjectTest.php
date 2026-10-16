<?php

namespace Quellabs\Recommender\Tests;

use PHPUnit\Framework\TestCase;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;
use Quellabs\Recommender\VisitorContext;
use Quellabs\Recommender\Config\RecommendationConfig;

/** Unit tests for Subject construction. */
class SubjectTest extends TestCase {

    /** @return void */
    public function testMemberSubjectCarriesItsId(): void {
        $subject = Subject::member(7);
        $this->assertSame(SubjectKind::Member, $subject->kind);
        $this->assertSame(7, $subject->id);
        $this->assertNull($subject->visitor);
    }

    /** @return void */
    public function testVisitorSubjectCarriesItsContext(): void {
        $context = new VisitorContext(new RecommendationConfig());
        $subject = Subject::visitor($context);
        $this->assertSame(SubjectKind::Visitor, $subject->kind);
        $this->assertNull($subject->id);
        $this->assertSame($context, $subject->visitor);
    }

    /** @return void */
    public function testProductSubjectCarriesItsId(): void {
        $subject = Subject::product(10);
        $this->assertSame(SubjectKind::Product, $subject->kind);
        $this->assertSame(10, $subject->id);
    }

    /** @return void */
    public function testRejectsIdsOutsideTheUnsigned32BitRange(): void {
        foreach ([fn() => Subject::member(-1), fn() => Subject::product(4294967296)] as $create) {
            try {
                $create();
                $this->fail('Out-of-range ID was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
