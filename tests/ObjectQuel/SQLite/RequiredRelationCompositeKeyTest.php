<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * RangeOptimizer::hasMatchingRequiredRelationAnnotation() must match a
	 * composite ManyToOne through its resolved RelationColumnMapping pairs,
	 * and shouldSetRangeRequired() must accept the AND-of-equalities join
	 * condition a composite `via` relation produces — not just a single
	 * `a.x = b.y` equality. Covers both directions: a @RequiredRelation
	 * composite relation (CkRequiredOrderLineEntity) promotes an explicit
	 * LEFT `via` join to INNER, while the same relation without the
	 * annotation (CkOptionalOrderLineEntity) stays LEFT.
	 */
	class RequiredRelationCompositeKeyTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['ck_required_order_lines', 'ck_optional_order_lines', 'ck_required_orders'];

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO ck_required_orders (tenant_id, number, amount) VALUES (1, 'A-1', 100)");

			// Matches the order on both key components.
			$this->exec("INSERT INTO ck_required_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-1', 10)");
			// Shares the first component with the order above but not the second —
			// the scenario the review flags: a first-component-only match must not
			// be treated as a full-tuple match.
			$this->exec("INSERT INTO ck_required_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-2', 20)");

			$this->exec("INSERT INTO ck_optional_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-1', 10)");
			$this->exec("INSERT INTO ck_optional_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-2', 20)");
		}

		public function testRequiredRelationPromotesACompositeViaLeftJoinToInnerJoin(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
				sort by ol.id asc
			");

			$this->assertStringContainsString('INNER JOIN', $plan->getSql()[0]);
			$this->assertStringNotContainsString('LEFT JOIN', $plan->getSql()[0]);

			$rows = $this->em->getAll("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
				sort by ol.id asc
			");

			// The orphan line (shares only the first key component) is dropped by
			// the INNER JOIN; a first-component-only match would have kept it.
			$this->assertCount(1, $rows);
			$this->assertSame(100, $rows[0]['o.amount']);
		}

		public function testRelationWithoutRequiredRelationRemainsALeftJoin(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
				sort by ol.id asc
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);

			$rows = $this->em->getAll("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
				sort by ol.id asc
			");

			// Both lines survive: the orphan's unmatched 'o' side is simply NULL.
			$this->assertCount(2, $rows);
			$this->assertSame(100, $rows[0]['o.amount']);
			$this->assertNull($rows[1]['o.amount']);
		}

		/**
		 * A manually written `via` condition names the same mapped properties
		 * as the composite @RequiredRelation ('ol.order') but joins the second
		 * component with `<>` instead of `=`. It must stay a LEFT JOIN: line 1
		 * ('A-1') never matches the order on this condition (its number equals
		 * the order's, so `<>` fails) and must survive with a NULL 'o.amount'.
		 * Promoting this to an INNER JOIN — the bug extractEqualityPairs()
		 * previously had, by ignoring the operator — would drop it.
		 */
		public function testNonEqualityConditionOnRequiredRelationRemainsALeftJoin(): void {
			$plan = $this->em->explainQuery("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.orderTenantId = o.tenantId and ol.orderNumber <> o.number
				retrieve (ol.id, o.amount)
				sort by ol.id asc
			");

			$this->assertStringContainsString('LEFT JOIN', $plan->getSql()[0]);

			$rows = $this->em->getAll("
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.orderTenantId = o.tenantId and ol.orderNumber <> o.number
				retrieve (ol.id, o.amount)
				sort by ol.id asc
			");

			// Line 1 ('A-1') doesn't satisfy the `<>` branch against the only
			// order ('A-1') and must survive with a NULL 'o.amount'. Line 2
			// ('A-2') does satisfy it and picks up the order's amount.
			$this->assertCount(2, $rows);
			$this->assertNull($rows[0]['o.amount']);
			$this->assertSame(100, $rows[1]['o.amount']);
		}
	}
