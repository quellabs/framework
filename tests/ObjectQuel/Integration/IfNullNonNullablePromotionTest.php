<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Regression coverage for DetectNonNullableField, fixed while
	 * investigating bug-soft-delete-left-join-boolean-null.md.
	 *
	 * A reference nested anywhere inside ifnull()/COALESCE() — either its
	 * checked (possibly-null) argument or its alt-value (fallback) argument
	 * — is not evidence that a LEFT JOIN range's row must exist.
	 *
	 * The first attempt at this fix exempted only the checked argument and
	 * left the alt-value argument counting as ordinary evidence. That's
	 * unsound: ifnull() only evaluates its alt value when the checked
	 * argument is NULL, so on any row where the checked argument is
	 * non-null, the alt value's presence or absence never affects the
	 * result — a reference there proves nothing about whether its range
	 * must exist. See IfNullAltValueUnmatchedRowTest for the concrete
	 * row-level bug this caused (a row with no related entity at all was
	 * wrongly dropped once the range was promoted to INNER JOIN on this
	 * unsound basis).
	 *
	 * PostEntity.userId is nullable, so `range of u via p.user` compiles to
	 * a LEFT JOIN by default; UserEntity.username has no `nullable` param
	 * (defaults to false per Column's constructor), making it a valid
	 * non-nullable reference to test promotion against.
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

		/**
		 * A reference nested deeper than a bare argument (e.g. inside a
		 * concat() call that is itself the alt value) must be exempted too —
		 * the exemption walks the full ancestor chain, not just the
		 * immediate parent, precisely so this case is covered.
		 */
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

		/**
		 * A reference to the same range outside any ifnull() call is
		 * unaffected by this exemption and still promotes normally — proves
		 * the fix didn't accidentally broaden into "never promote when
		 * ifnull() appears anywhere in the query."
		 */
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
