<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * TableUsageInfo::canSafelyCollapseToInner() (consulted by
	 * AnyOptimizer::collapseSourceRangesToInner() when building an any(...)'s
	 * EXISTS/CASE WHEN subquery) must only promote a LEFT-joined range to INNER
	 * when something unconditionally requires a match for every row:
	 *
	 *  - A non-nullable field reference reachable only through an OR does not
	 *    prove that (the other OR branch can still be true with no match at
	 *    all) — see RangeUsageAnalyzer's use of AstUtilities::isNestedInsideOr().
	 *  - Not being referenced in the condition at all proves nothing either:
	 *    the range is still joined into the subquery's FROM, so an unmatched
	 *    row can still change whether the subquery is empty, independent of
	 *    the WHERE clause. canSafelyCollapseToInner() no longer treats
	 *    "unused in conditions" as a safety signal on its own.
	 *
	 * Both scenarios below would have collapsed 'o' to INNER JOIN, silently
	 * turning a true EXISTS into a false one by dropping the unmatched row's
	 * virtual row entirely.
	 */
	class AnyOptimizerCollapseToInnerTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['ck_optional_order_lines', 'ck_required_orders'];

		protected function seedFixtures(): void {
			// Deliberately tenant 2, not 1 -- "o.tenantId = 1" is never true for
			// a matched row, so the only way either query below can be true is
			// through the orphan's own 'ol' column, independent of 'o'.
			$this->exec("INSERT INTO ck_required_orders (tenant_id, number, amount) VALUES (2, 'A-1', 100)");
			// Matches the order (tenant 2, 'A-1'), lineAmount = 10.
			$this->exec("INSERT INTO ck_optional_order_lines (order_tenant_id, order_number, line_amount) VALUES (2, 'A-1', 10)");
			// Orphan: no matching order at all (tenant 1, 'A-2'), lineAmount = 20.
			$this->exec("INSERT INTO ck_optional_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-2', 20)");
		}

		/**
		 * `o.tenantId = 1 or ol.lineAmount = 20` — the non-nullable reference
		 * to 'o.tenantId' sits inside an OR, so it must not promote 'o'. The
		 * orphan's own lineAmount=20 satisfies the OR regardless of 'o', so the
		 * EXISTS must be true.
		 */
		public function testNonNullableReferenceInsideOrDoesNotCollapseTheJoin(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (flag = any(o.amount where o.tenantId = 1 or ol.lineAmount = 20))
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);

			$rows = $this->em->getAll("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (flag = any(o.amount where o.tenantId = 1 or ol.lineAmount = 20))
			");

			foreach ($rows as $row) {
				$this->assertSame(1, (int)$row['flag']);
			}
		}

		/**
		 * `o` referenced only in the projected expr, never in the condition at
		 * all. "ol.lineAmount = 20" alone must not collapse 'o': the orphan
		 * satisfies it with no match for 'o' at all, so the EXISTS must still
		 * be true.
		 */
		public function testRangeUnusedInConditionsDoesNotCollapseTheJoin(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (flag = any(o.amount where ol.lineAmount = 20))
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);

			$rows = $this->em->getAll("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (flag = any(o.amount where ol.lineAmount = 20))
			");

			foreach ($rows as $row) {
				$this->assertSame(1, (int)$row['flag']);
			}
		}

		/**
		 * The legitimate case must keep working: a non-nullable reference that
		 * is unconditionally required (AND-only, no OR) still collapses the
		 * join to INNER.
		 */
		public function testUnconditionallyRequiredNonNullableReferenceStillCollapsesTheJoin(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (flag = any(o.amount where ol.lineAmount = 10 and o.tenantId = 2))
			");

			$this->assertStringContainsString('INNER JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('LEFT JOIN', $plan->getSql()[0]);
		}
	}
