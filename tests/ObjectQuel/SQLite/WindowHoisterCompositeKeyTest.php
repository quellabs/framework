<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Regression coverage for AbstractSequenceFunctionHoister's helper-range
	 * join-back (used by WhereWindowFilterRewriter/WindowChainRewriter) against
	 * a composite primary key: the helper subquery must join back to the outer
	 * range on every key component, not just the first.
	 *
	 * Two tenants, each with two orders, so tenantId alone never identifies a
	 * single row — if the join-back matched on tenantId only, each outer row
	 * would fan out against both of its tenant's helper rows, duplicating rows
	 * and corrupting the row_number() result this test asserts on.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class WindowHoisterCompositeKeyTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM cki_orders');

			$orders = [
				[1, 'A-1', 10],
				[1, 'A-2', 20],
				[2, 'B-1', 30],
				[2, 'B-2', 40],
			];

			foreach ($orders as [$tenantId, $number, $amount]) {
				$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES ({$tenantId}, '{$number}', {$amount})");
			}

			self::em()->getUnitOfWork()->clear();
		}

		public function testWhereWindowFilterHoisterJoinsBackOnTheFullCompositeKey(): void {
			$em = self::em();

			// row_number() can't be referenced in the same query block's WHERE, so
			// `where rn <= 2` forces WhereWindowFilterRewriter to extract it into a
			// helper range joined back on CkiOrderEntity's primary key. Both
			// tenantId and number are excluded from the inferred SELECT-driven
			// partition (both are primary key components), leaving a single,
			// unpartitioned global ranking by amount.
			$result = iterator_to_array($em->executeQuery('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity
				retrieve (o.tenantId, o.number, rn = row_number(sort by o.amount desc))
				where rn <= 2
				sort by o.tenantId, o.number
			'));

			self::assertSame(
				[[2, 'B-1', 2], [2, 'B-2', 1]],
				array_map(
					static fn($row) => [(int)$row['o.tenantId'], $row['o.number'], (int)$row['rn']],
					$result
				)
			);
		}
	}
