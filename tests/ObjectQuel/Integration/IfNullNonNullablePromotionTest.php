<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Regression coverage for DetectNonNullableField: a reference nested inside
	 * ifnull()/COALESCE() (either argument) must not count as evidence that a
	 * LEFT JOIN range's row must exist. See IfNullAltValueUnmatchedRowTest for
	 * the row-level bug this caused.
	 */
	class IfNullNonNullablePromotionTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['posts', 'users'];

		public function testAltValueReferenceDoesNotPromoteToInnerJoin(): void {
			$plan = $this->em->explainQuery("
				range of p is App\\Entities\\PostEntity
				range of u is App\\Entities\\UserEntity via p.user
				retrieve (p.id)
				where ifnull(p.title, u.username) = 'fallback'
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);
		}

		public function testPrimaryValueReferenceDoesNotPromoteToInnerJoin(): void {
			$plan = $this->em->explainQuery("
				range of p is App\\Entities\\PostEntity
				range of u is App\\Entities\\UserEntity via p.user
				retrieve (p.id)
				where ifnull(u.username, 'fallback') = 'fallback'
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);
		}

		/** A reference nested deeper than a bare argument (e.g. inside concat()) must be exempted too. */
		public function testReferenceNestedInsideAltValueDoesNotPromoteToInnerJoin(): void {
			$plan = $this->em->explainQuery("
				range of p is App\\Entities\\PostEntity
				range of u is App\\Entities\\UserEntity via p.user
				retrieve (p.id)
				where ifnull(p.title, concat(u.username, 'x')) = 'fallback'
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);
		}

		/** A reference outside any ifnull() call still promotes normally. */
		public function testUnrelatedReferenceOutsideIfNullStillPromotesToInnerJoin(): void {
			$plan = $this->em->explainQuery("
				range of p is App\\Entities\\PostEntity
				range of u is App\\Entities\\UserEntity via p.user
				retrieve (p.id)
				where ifnull(p.title, 'fallback') = 'fallback' and u.username = 'alice'
			");

			$this->assertStringContainsString('INNER JOIN', $plan->getSql()[0]);
		}
	}
