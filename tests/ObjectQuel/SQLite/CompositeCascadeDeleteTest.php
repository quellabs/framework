<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderEntity;

	/**
	 * UnitOfWork::cascadeDeleteDependentObjects() matching on the full composite
	 * foreign key, not just its first component, when a Cascade(remove) parent
	 * is deleted.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class CompositeCascadeDeleteTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM cki_orders');
			$adapter->execute('DELETE FROM cki_order_lines');

			// Two orders sharing tenant_id=1 but a different number — a cascade
			// matching on tenant_id alone would delete both orders' lines instead
			// of only the deleted order's.
			$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES (1, 'A-1', 100)");
			$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES (1, 'A-2', 200)");
			$adapter->execute("INSERT INTO cki_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-1', 10)");
			$adapter->execute("INSERT INTO cki_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-1', 20)");
			$adapter->execute("INSERT INTO cki_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-2', 999)");

			self::em()->getUnitOfWork()->clear();
		}

		public function testDeletingOneOrderOnlyCascadesToItsOwnLinesNotASiblingSharingTheFirstKeyComponent(): void {
			$em = self::em();

			$orderA1 = $em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'A-1']);
			self::assertNotNull($orderA1);

			$em->remove($orderA1);
			$em->flush();

			$remainingLines = $em->getConnection()->execute(
				'SELECT order_number, line_amount FROM cki_order_lines ORDER BY line_amount'
			)->fetchAll('assoc');

			// Only the A-2 line (999) survives; both A-1 lines (10, 20) are gone.
			self::assertCount(1, $remainingLines);
			self::assertSame('A-2', $remainingLines[0]['order_number']);
			self::assertSame(999, (int) $remainingLines[0]['line_amount']);

			$remainingOrders = $em->getConnection()->execute(
				'SELECT number FROM cki_orders'
			)->fetchAll('assoc');

			self::assertCount(1, $remainingOrders);
			self::assertSame('A-2', $remainingOrders[0]['number']);
		}
	}
