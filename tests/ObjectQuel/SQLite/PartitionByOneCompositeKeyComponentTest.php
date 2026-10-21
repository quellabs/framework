<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Regression coverage for AstUtilities::excludePrimaryKeyItems(): a SELECT
	 * list naming only one component of a composite key (e.g. CkiOrderEntity's
	 * tenantId, without its sibling number) is a deliberate partitioning key,
	 * not a reference to the row's complete identity, and must remain eligible
	 * for inferred PARTITION BY. Only a SELECT list naming every component
	 * together counts as "the row's own identity" and is excluded (covered by
	 * WindowHoisterCompositeKeyTest). Covers both the direct window form
	 * (AggregateOptimizer) and the hoisted form (AbstractSequenceFunctionHoister,
	 * forced by a `where` filter on the window result).
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class PartitionByOneCompositeKeyComponentTest extends TestCase {

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

		public function testDirectWindowInfersPartitionByTheSingleSelectedKeyComponent(): void {
			$em = self::em();

			$result = iterator_to_array($em->executeQuery('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity
				retrieve (o.tenantId, o.amount, rn = row_number(sort by o.amount desc))
				sort by o.tenantId, o.amount desc
			'));

			// Ranked within each tenant, not globally across both tenants: a global
			// ranking would put amount 40 at rn=1 and amount 10 at rn=4.
			self::assertSame(
				[[1, 20, 1], [1, 10, 2], [2, 40, 1], [2, 30, 2]],
				array_map(
					static fn($row) => [(int)$row['o.tenantId'], (int)$row['o.amount'], (int)$row['rn']],
					$result
				)
			);
		}

		public function testHoistedWindowFilterInfersPartitionByTheSingleSelectedKeyComponent(): void {
			$em = self::em();

			// `where rn <= 1` can't reference the window result directly, forcing
			// WhereWindowFilterRewriter to hoist it into a helper range (exercising
			// AbstractSequenceFunctionHoister::inferOuterPartitionItems() instead of
			// AggregateOptimizer's own inline partition inference).
			$result = iterator_to_array($em->executeQuery('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity
				retrieve (o.tenantId, o.amount, rn = row_number(sort by o.amount desc))
				where row_number(sort by o.amount desc) <= 1
				sort by o.tenantId
			'));

			// One top row per tenant, not one top row overall.
			self::assertSame(
				[[1, 20, 1], [2, 40, 1]],
				array_map(
					static fn($row) => [(int)$row['o.tenantId'], (int)$row['o.amount'], (int)$row['rn']],
					$result
				)
			);
		}
	}
