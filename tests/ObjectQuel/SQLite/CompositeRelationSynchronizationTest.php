<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderLineEntity;

	/**
	 * UnitOfWork::extractParentPrimaryKeyData() syncing every component of a
	 * composite ManyToOne's foreign key onto the child before insert — not just
	 * the first component — and reporting a partially-resolved composite parent
	 * instead of silently writing an inconsistent row.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class CompositeRelationSynchronizationTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM cki_orders');
			$adapter->execute('DELETE FROM cki_order_lines');

			self::em()->getUnitOfWork()->clear();
		}

		public function testNewChildReferencingANewCompositeKeyParentSyncsEveryComponent(): void {
			$em = self::em();

			$order = new CkiOrderEntity();
			$order->setTenantId(7);
			$order->setNumber('B-9');
			$order->setAmount(500);

			$line = new CkiOrderLineEntity();
			$line->order = $order;
			$line->setLineAmount(42);

			// Both entities are new and persisted together in one commit; the
			// topological sort (scheduleEntitiesForPersistence()) must insert the
			// parent first so both of its composite key components are available
			// when the child's FK columns are synced from it.
			$em->persist($order);
			$em->persist($line);
			$em->flush();

			$orderRow = $em->getConnection()->execute(
				'SELECT tenant_id, number FROM cki_orders WHERE amount = 500'
			)->fetchAll('assoc')[0];

			self::assertSame(7, (int) $orderRow['tenant_id']);
			self::assertSame('B-9', $orderRow['number']);

			$lineRow = $em->getConnection()->execute(
				'SELECT order_tenant_id, order_number FROM cki_order_lines WHERE line_amount = 42'
			)->fetchAll('assoc')[0];

			self::assertSame(7, (int) $lineRow['order_tenant_id']);
			self::assertSame('B-9', $lineRow['order_number']);
		}

		public function testPartiallyResolvedCompositeParentIsReportedNotSilentlyWritten(): void {
			$em = self::em();

			// Simulates a composite parent that only has one of its two key
			// components available — an inconsistent state no legitimate caller
			// intends, as opposed to a parent that simply isn't persisted at all
			// (every component null), which stays a valid no-op (see
			// RelationshipCascadeForeignKeyTest's "cascade persist absent" cases).
			$order = new CkiOrderEntity();
			$order->setTenantId(7);

			$line = new CkiOrderLineEntity();
			$line->order = $order;
			$line->setLineAmount(99);

			$em->persist($line);

			$this->expectException(OrmException::class);
			$this->expectExceptionMessageMatches('/only some of its referenced key components/');

			$em->flush();
		}
	}
