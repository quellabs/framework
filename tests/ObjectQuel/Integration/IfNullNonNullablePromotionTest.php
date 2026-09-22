<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Regression coverage for DetectNonNullableField, fixed while
	 * investigating bug-soft-delete-left-join-boolean-null.md: a reference
	 * used as ifnull()/COALESCE()'s primary (possibly-null) argument no
	 * longer counts as evidence that a LEFT JOIN range's row must exist,
	 * since that's precisely the case the function exists to handle.
	 *
	 * That exemption must be narrow: a reference used as the *alt value*
	 * (the fallback returned when the primary argument is NULL) is an
	 * ordinary reference and must still count — it says nothing about
	 * whether the joined row exists, and dropping the promotion there would
	 * make the optimizer miss a real INNER JOIN opportunity.
	 *
	 * PostEntity.userId is nullable, so `range of u via p.user` compiles to
	 * a LEFT JOIN by default; UserEntity.username has no `nullable` param
	 * (defaults to false per Column's constructor), making it a valid
	 * non-nullable reference to test promotion against.
	 */
	class IfNullNonNullablePromotionTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['posts', 'users'];

		public function testAltValueReferenceStillPromotesToInnerJoin(): void {
			$plan = $this->em->explainQuery("
				range of p is App\\Entities\\PostEntity
				range of u is App\\Entities\\UserEntity via p.user
				retrieve (p.id)
				where ifnull(p.title, u.username) = 'fallback'
			");

			$this->assertStringContainsString('INNER JOIN', $plan->getSql()[0]);
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
	}
