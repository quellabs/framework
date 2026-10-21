<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityNotFoundException;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderLineEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderLineLazyEntity;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;

	/**
	 * End-to-end EntityManager::find()/findOrFail() against a real composite
	 * primary key: two rows sharing their first key component, and the managed
	 * identity map returning the same instance on a repeat lookup. Complements
	 * the pure-metadata coverage in Unit/Metadata/CompositeIdentifierTest.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class CompositePrimaryKeyFindTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM cki_orders');
			$adapter->execute('DELETE FROM cki_order_lines');
			$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES (1, 'A-1', 100)");
			$adapter->execute("INSERT INTO cki_orders (tenant_id, number, amount) VALUES (1, 'A-2', 200)");
			$adapter->execute("INSERT INTO cki_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-1', 10)");
			$adapter->execute("INSERT INTO cki_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-1', 20)");
			$adapter->execute("INSERT INTO cki_order_lines (order_tenant_id, order_number, line_amount) VALUES (1, 'A-2', 999)");
			$adapter->execute('DELETE FROM cki_order_lines_lazy');
			$adapter->execute("INSERT INTO cki_order_lines_lazy (order_tenant_id, order_number) VALUES (1, 'A-1')");

			self::em()->getUnitOfWork()->clear();
		}

		public function testFindDistinguishesTwoRowsSharingTheFirstKeyComponent(): void {
			$em = self::em();

			$orderA1 = $em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'A-1']);
			$orderA2 = $em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'A-2']);

			self::assertNotNull($orderA1);
			self::assertNotNull($orderA2);
			self::assertNotSame($orderA1, $orderA2);
			self::assertSame(100, $orderA1->getAmount());
			self::assertSame(200, $orderA2->getAmount());
		}

		public function testFindReturnsTheSameManagedInstanceOnARepeatLookup(): void {
			$em = self::em();

			$first = $em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'A-1']);
			$second = $em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'A-1']);

			self::assertSame($first, $second);
		}

		public function testFindReturnsNullForANonExistentCompositeKey(): void {
			$em = self::em();

			self::assertNull($em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'does-not-exist']));
		}

		public function testFindOrFailThrowsForANonExistentCompositeKey(): void {
			$em = self::em();

			$this->expectException(EntityNotFoundException::class);

			$em->findOrFail(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'does-not-exist']);
		}

		public function testFindRejectsAScalarPrimaryKeyForACompositeEntity(): void {
			$em = self::em();

			$this->expectException(\InvalidArgumentException::class);

			$em->find(CkiOrderEntity::class, 1);
		}

		public function testFindRejectsAPartialCompositeKey(): void {
			$em = self::em();

			$this->expectException(\InvalidArgumentException::class);

			$em->find(CkiOrderEntity::class, ['tenantId' => 1]);
		}

		public function testTwoDistinctManagedInstancesWithTheSameCompositeIdentityFailExplicitly(): void {
			$em = self::em();

			// Loads and manages the real row under its own instance.
			$em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'A-1']);

			// A second, distinct object claiming the exact same identity must not
			// silently overwrite the first instance's index entry.
			$duplicate = new CkiOrderEntity();
			$duplicate->setTenantId(1);
			$duplicate->setNumber('A-1');
			$duplicate->setAmount(999);

			$this->expectException(EntityResolutionException::class);

			$em->getUnitOfWork()->persistExisting($duplicate);
		}

		public function testEagerManyToOneJoinsOnTheFullCompositeKeyNotJustTheFirstComponent(): void {
			$em = self::em();

			// Both A-1 and A-2 share tenantId=1; only the line whose order_number also
			// matches 'A-1' may come back linked to it — a join on tenantId alone would
			// non-deterministically pick either order.
			$lines = $em->findBy(CkiOrderLineEntity::class, ['lineAmount' => 10]);
			self::assertCount(1, $lines);

			$line = $lines[0];
			self::assertNotNull($line->order);
			self::assertSame('A-1', $line->order->getNumber());
			self::assertSame(100, $line->order->getAmount());
		}

		public function testEagerInverseOfCollectionMatchesOnTheFullCompositeKeyNotJustTheFirstComponent(): void {
			$em = self::em();

			$order = $em->find(CkiOrderEntity::class, ['tenantId' => 1, 'number' => 'A-1']);
			self::assertNotNull($order);

			// Exactly the two lines belonging to A-1 (10, 20) — not the A-2 line (999)
			// that shares the same tenantId.
			$amounts = array_map(
				static fn(CkiOrderLineEntity $line) => $line->getLineAmount(),
				$order->lines->toArray()
			);
			sort($amounts);

			self::assertSame([10, 20], $amounts);
		}

		public function testLazyManyToOneProxySeedsAllIdentifierComponentsWithoutTriggeringInitialization(): void {
			$em = self::em();

			$lines = $em->findBy(CkiOrderLineLazyEntity::class, []);
			self::assertCount(1, $lines);

			$order = $lines[0]->order;
			self::assertInstanceOf(ProxyInterface::class, $order);
			self::assertFalse($order->isInitialized());

			// Every identifier getter — not just the first component — must read the
			// seeded value directly, without triggering a database load.
			self::assertSame(1, $order->getTenantId());
			self::assertSame('A-1', $order->getNumber());
			self::assertFalse($order->isInitialized());

			// A non-identifier getter still triggers a real load.
			self::assertSame(100, $order->getAmount());
			self::assertTrue($order->isInitialized());
		}
	}
