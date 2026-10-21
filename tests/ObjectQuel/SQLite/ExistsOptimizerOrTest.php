<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * ExistsOptimizer must only extract an `exists(range)` call and promote its
	 * range to INNER JOIN when the call is unconditionally required — reachable
	 * from the WHERE clause's root through AND alone. An `exists(range)` behind
	 * an OR does not prove every row has a match (the other OR branch can still
	 * be true with none at all), so it must stay a LEFT JOIN, and the call itself
	 * must be rewritten to a renderable `<primary key> IS NOT NULL` check rather
	 * than left as a bare AstExists node (which has no SQL rendering) or silently
	 * dropped along with the rest of the OR.
	 */
	class ExistsOptimizerOrTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['ck_optional_order_lines', 'ck_required_orders'];

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO ck_required_orders (tenant_id, number, amount) VALUES (1, 'A-1', 100)");

			// Matches the order — exists(o) is true for this line.
			$this->exec("INSERT INTO ck_optional_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-1', 10)");
			// Orphan — exists(o) is false, but satisfies the OR's other branch.
			$this->exec("INSERT INTO ck_optional_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-2', 20)");
		}

		/** An `exists(o)` alone (AND-reachable from the root) still promotes 'o' to INNER JOIN and drops the now-redundant check. */
		public function testBareExistsStillPromotesToInnerJoinAndRemovesTheCheck(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
				where exists(o)
			");

			$this->assertStringContainsString('INNER JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('LEFT JOIN', $plan->getSql()[0]);

			$rows = $this->em->getAll("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
				where exists(o)
			");

			$this->assertCount(1, $rows);
			$this->assertSame(100, $rows[0]['o.amount']);
		}

		/**
		 * `exists(o)` OR-ed with an unrelated condition on 'ol' must leave 'o' as
		 * a LEFT JOIN, and both rows must survive: the matched line through
		 * exists(o), the orphan through the OR's other branch with 'o.amount' NULL.
		 * Before the fix, this generated an INNER JOIN and dropped the orphan
		 * entirely (0 rows), because the OR-nested exists() was extracted and its
		 * range forced required exactly like an AND-reachable one.
		 */
		public function testExistsOrEdWithAnotherConditionKeepsTheJoinLeftAndSurvivesBothRows(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, ol.lineAmount, o.amount)
				where ol.lineAmount = 20 or exists(o)
				sort by ol.id asc
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('INNER JOIN', $plan->getSql()[0]);

			$rows = $this->em->getAll("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, ol.lineAmount, o.amount)
				where ol.lineAmount = 20 or exists(o)
				sort by ol.id asc
			");

			$this->assertCount(2, $rows);
			$this->assertSame(100, $rows[0]['o.amount']);
			$this->assertNull($rows[1]['o.amount']);
		}
	}
