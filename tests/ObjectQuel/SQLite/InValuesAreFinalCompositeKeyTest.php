<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderEntity;

	/**
	 * Regression coverage for QuelToSQLRetrieve::getSortUsingIn(): a composite
	 * primary key can never be expressed as a single-column IN() literal list,
	 * so a condition on only its first component must never be treated as an
	 * ordered list of complete row identities under @InValuesAreFinal. Two rows
	 * sharing that first component (CkiOrderEntity's tenantId) but differing in
	 * the second (number) expose the bug: the old FIELD()/CASE path sorted on
	 * tenantId alone, leaving the two rows tied and falling back to whatever
	 * order the engine happened to return them in.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class InValuesAreFinalCompositeKeyTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM cki_orders');

			self::em()->getUnitOfWork()->clear();
		}

		public function testCompositeKeyIgnoresTheSingleColumnInListOrderAndSortsByTheFullTuple(): void {
			$em = self::em();
			$adapter = $em->getConnection();

			// Inserted in descending 'number' order, within the shared tenantId=1,
			// so a result that merely preserved insertion/engine order (the old
			// tied-CASE fallback) would come back as A-2, A-1 — the opposite of
			// the full-tuple ascending order the fix must produce.
			$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES (1, 'A-2', 20)");
			$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES (1, 'A-1', 10)");

			$query = '
				@InValuesAreFinal true
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity
				retrieve (o.tenantId, o.number)
				where o.tenantId in (1)
			';

			$plan = $em->explainQuery($query);

			// The single-column FIELD()/CASE detector must never fire for a
			// composite key: it would sort on tenantId alone and never mention
			// the tie-breaking 'number' column at all.
			self::assertStringNotContainsString('CASE', $plan->getSql()[0]);
			self::assertStringNotContainsString('FIELD(', $plan->getSql()[0]);
			self::assertStringContainsString('number', $plan->getSql()[0]);

			$rows = $em->getAll($query);

			self::assertSame(['A-1', 'A-2'], array_column($rows, 'o.number'));
		}
	}
