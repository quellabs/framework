<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Tests\Fixtures\BadCascadeEntities\RelParentWithBadCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelDepartmentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelEmployeeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelOrderCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelOrderNoCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileNoCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileUnidirectionalEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileUnidirectionalPersistEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelUserEntity;

	/**
	 * End-to-end validation of Cascade and ForeignKey/ForeignKeyAction for two
	 * relationship shapes: OneToOne (bidirectional owning side), and the
	 * "one-to-many" shape this ORM expresses as ManyToOne (owning, "many"
	 * side) + InverseOf (collection, "one" side) — there is no standalone
	 * OneToMany annotation. See Cascade.php for why remove lives on the
	 * ManyToOne side and persist on the InverseOf side for that shape.
	 *
	 * Exercises real persist/remove/flush cycles through UnitOfWork against
	 * MySQL, not just the private shouldCascadeRemove() decision (see
	 * CascadeStrategyTest for that narrower check).
	 *
	 * Uses its own isolated fixture directory (tests/ObjectQuel/Fixtures/RelationshipEntities)
	 * rather than the shared tests/ObjectQuel/Fixtures/Entities used by the
	 * metadata-only FK suites, because remove()+flush() triggers
	 * EntityStore::getOrderedDependentEntities(), which eagerly builds metadata
	 * for every entity in the configured entity_path — including fixtures like
	 * FkOrderActionNoFkEntity that are deliberately invalid for other tests'
	 * purposes.
	 *
	 * The EntityManager itself comes from the suite's shared $GLOBALS['test_em']
	 * — a genuine process-wide singleton, not just per-class — because
	 * UnitOfWork registers a standalone 'orm.prePersist' signal on the
	 * process-wide SignalHub with no "already registered" guard, so a second
	 * EntityManager anywhere else in the same PHPUnit process throws. This
	 * class's own tables are created idempotently in setUp() (CREATE TABLE IF
	 * NOT EXISTS) since another test class may have claimed the connection
	 * first, and table state between tests is reset via DELETE FROM.
	 */
	class RelationshipCascadeForeignKeyTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_customers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_orders_cascade (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_orders_no_cascade (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_profiles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_profiles_unidirectional (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_profiles_unidirectional_persist (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_profiles_no_cascade (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_departments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_employees (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, department_id INT) ENGINE=InnoDB');

			foreach ([
				'rel_orders_cascade', 'rel_orders_no_cascade',
				'rel_profiles', 'rel_profiles_unidirectional', 'rel_profiles_unidirectional_persist', 'rel_profiles_no_cascade',
				'rel_customers', 'rel_users',
				'rel_employees', 'rel_departments',
			] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			self::em()->getUnitOfWork()->clear();
		}

		// -------------------------------------------------------------------------
		// ManyToOne + InverseOf ("one-to-many") — Cascade(remove), end to end
		// -------------------------------------------------------------------------

		public function testCascadeRemovePresentDeletesOrdersWhenCustomerIsRemoved(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$em->persist($customer);
			$em->flush();

			$order = new RelOrderCascadeEntity();
			$order->customer = $customer;
			$em->persist($order);
			$em->flush();

			$em->remove($customer);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_orders_cascade')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		/**
		 * A New (persist()ed but never flushed) order referencing the customer
		 * has no row in the database yet, so the DB-driven lookup in
		 * cascadeDeleteDependentObjects() can never find it. Without
		 * cascadeDeleteUnpersistedDependents() catching it in memory, it would
		 * still get inserted after the customer's DELETE, pointing at a
		 * customer row that no longer exists.
		 */
		public function testCascadeRemoveCatchesAnUnflushedNewOrderInTheSameUnitOfWork(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$em->persist($customer);
			$em->flush();

			$order = new RelOrderCascadeEntity();
			$order->customer = $customer;
			$em->persist($order);

			$em->remove($customer);
			$em->flush();

			self::assertNull($order->getId());

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_orders_cascade')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		public function testCascadeRemoveAbsentLeavesOrdersInPlaceWhenCustomerIsRemoved(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$em->persist($customer);
			$em->flush();

			$order = new RelOrderNoCascadeEntity();
			$order->customer = $customer;
			$em->persist($order);
			$em->flush();

			$em->remove($customer);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_orders_no_cascade')->fetchAll('assoc');
			self::assertCount(1, $remaining);
		}

		// -------------------------------------------------------------------------
		// ManyToOne + InverseOf ("one-to-many") — Cascade(persist), end to end
		// -------------------------------------------------------------------------

		public function testCascadePersistAutoSavesTheRelatedEntityOnAManyToOneOwningSide(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$order = new RelOrderCascadeEntity();
			$order->customer = $customer;

			// Cascade(persist) on $customer should pick up and insert the
			// unsaved customer too.
			$em->persist($order);
			$em->flush();

			self::assertNotNull($customer->getId());

			$rows = $em->getConnection()->execute('SELECT id FROM rel_customers')->fetchAll('assoc');
			self::assertCount(1, $rows);
		}

		public function testCascadePersistAbsentLeavesTheRelatedCustomerUnsavedOnAManyToOneOwningSide(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$order = new RelOrderNoCascadeEntity();
			$order->customer = $customer;

			$em->persist($order);
			$em->flush();

			self::assertNull($customer->getId());

			$rows = $em->getConnection()->execute('SELECT id FROM rel_customers')->fetchAll('assoc');
			self::assertSame([], $rows);
		}

		// -------------------------------------------------------------------------
		// OneToOne (bidirectional owning side) — Cascade(remove), end to end
		// -------------------------------------------------------------------------

		public function testCascadeRemoveWorksForBidirectionalOneToOne(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$em->persist($user);
			$em->flush();

			$profile = new RelProfileEntity();
			$profile->user = $user;
			$em->persist($profile);
			$em->flush();

			$em->remove($user);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_profiles')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		/**
		 * A unidirectional OneToOne (no 'referencedColumn') must cascade-remove
		 * just like a bidirectional one: 'referencedColumn' only affects
		 * bidirectional setter sync codegen, it has nothing to do with whether
		 * cascade-remove runs — exactly as ManyToOne (which has no such
		 * distinction) already proves.
		 */
		public function testCascadeRemoveWorksForUnidirectionalOneToOneToo(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$em->persist($user);
			$em->flush();

			$profile = new RelProfileUnidirectionalEntity();
			$profile->user = $user;
			$em->persist($profile);
			$em->flush();

			$em->remove($user);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_profiles_unidirectional')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		public function testCascadeRemoveAbsentLeavesProfileInPlaceWhenUserIsRemoved(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$em->persist($user);
			$em->flush();

			$profile = new RelProfileNoCascadeEntity();
			$profile->user = $user;
			$em->persist($profile);
			$em->flush();

			$em->remove($user);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_profiles_no_cascade')->fetchAll('assoc');
			self::assertCount(1, $remaining);
		}

		// -------------------------------------------------------------------------
		// OneToOne — Cascade(persist), end to end
		// -------------------------------------------------------------------------

		public function testCascadePersistAutoSavesTheRelatedEntityOnAOneToOneOwningSide(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$profile = new RelProfileEntity();
			$profile->user = $user;

			// Cascade(persist) on $user should pick up and insert the unsaved
			// user too.
			$em->persist($profile);
			$em->flush();

			self::assertNotNull($user->getId());

			$rows = $em->getConnection()->execute('SELECT id FROM rel_users')->fetchAll('assoc');
			self::assertCount(1, $rows);
		}

		/**
		 * RelProfileUnidirectionalEntity declares Cascade(remove) only, no
		 * "persist" — reused here as the negative control instead of a new
		 * fixture, since it already has exactly the annotation shape needed.
		 */
		public function testCascadePersistAbsentLeavesTheRelatedUserUnsavedOnAOneToOneOwningSide(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$profile = new RelProfileUnidirectionalEntity();
			$profile->user = $user;

			$em->persist($profile);
			$em->flush();

			self::assertNull($user->getId());

			$rows = $em->getConnection()->execute('SELECT id FROM rel_users')->fetchAll('assoc');
			self::assertSame([], $rows);
		}

		/**
		 * Regression test for scheduleEntitiesForPersistence()'s dependency
		 * graph: it used to skip a unidirectional owning-side OneToOne
		 * relation (no 'referencedColumn') when building insert order, so a
		 * cascaded, brand-new parent reached only through such a relation
		 * could be scheduled after its child — inserting the child with a
		 * NULL FK instead of the parent's generated id. 'referencedColumn'
		 * only affects bidirectional setter-sync codegen; it has no bearing
		 * on insert ordering, exactly as it has none on cascade-remove
		 * eligibility (see testCascadeRemoveWorksForUnidirectionalOneToOneToo).
		 */
		public function testCascadePersistOrdersANewParentBeforeAUnidirectionalOneToOneChild(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$profile = new RelProfileUnidirectionalPersistEntity();
			$profile->user = $user;

			// Cascade(persist) on $user should pick up and insert the unsaved
			// user too — and, crucially, before $profile, since $profile's FK
			// column needs the user's generated id.
			$em->persist($profile);
			$em->flush();

			self::assertNotNull($user->getId());

			$rows = $em->getConnection()->execute('SELECT user_id FROM rel_profiles_unidirectional_persist')->fetchAll('assoc');
			self::assertCount(1, $rows);
			self::assertSame($user->getId(), (int) $rows[0]['user_id']);
		}

		// -------------------------------------------------------------------------
		// ForeignKey/ForeignKeyAction — metadata support for OneToOne
		// -------------------------------------------------------------------------

		public function testForeignKeyAndForeignKeyActionResolveForAOneToOneOwningSide(): void {
			$em = self::em();
			$comparator = new ForeignKeyComparator($em->getConnection(), $em->getEntityStore(), new PlatformCapabilities($em->getConnection()));

			$result = $comparator->getEntityForeignKeys(RelProfileEntity::class);

			self::assertArrayHasKey('fk_rel_profiles_user_id', $result);
			self::assertSame(['user_id'], $result['fk_rel_profiles_user_id']['columns']);
			self::assertSame('rel_users', $result['fk_rel_profiles_user_id']['referencedTable']);
			self::assertSame('CASCADE', $result['fk_rel_profiles_user_id']['onDelete']);
			self::assertSame('NO ACTION', $result['fk_rel_profiles_user_id']['onUpdate']);
		}

		// -------------------------------------------------------------------------
		// ManyToOne + InverseOf ("one-to-many") — Cascade(persist), end to end
		// -------------------------------------------------------------------------

		public function testCascadePersistAutoSavesNewChildrenOnAnInverseOfCollection(): void {
			$em = self::em();

			$department = new RelDepartmentEntity();
			$employee = new RelEmployeeEntity();
			$employee->department = $department;
			$department->employees->add($employee);

			// Cascade(persist) on $employees should walk the collection and
			// insert the unsaved employee too.
			$em->persist($department);
			$em->flush();

			self::assertNotNull($employee->getId());

			$rows = $em->getConnection()->execute('SELECT id FROM rel_employees')->fetchAll('assoc');
			self::assertCount(1, $rows);
		}

		/**
		 * RelCustomerEntity::$orders (InverseOf) declares no Cascade — reused
		 * here as the negative control instead of a new fixture.
		 */
		public function testCascadePersistAbsentLeavesNewChildrenUnsavedOnAnInverseOfCollection(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$order = new RelOrderCascadeEntity();
			$order->customer = $customer;
			$customer->orders->add($order);

			$em->persist($customer);
			$em->flush();

			self::assertNotNull($customer->getId());
			self::assertNull($order->getId());

			$rows = $em->getConnection()->execute('SELECT id FROM rel_orders_cascade')->fetchAll('assoc');
			self::assertSame([], $rows);
		}

		// -------------------------------------------------------------------------
		// By design, not a bug: Cascade(remove) on InverseOf is rejected at
		// build time — see Cascade.php. Uses its own EntityStore (not
		// EntityManager) pointed at the bad-fixtures directory, since loading
		// this pair is expected to throw.
		// -------------------------------------------------------------------------

		public function testCascadeRemoveOnAnInverseOfCollectionIsRejectedByDesign(): void {
			$badFixturesDir = __DIR__ . '/../Fixtures/BadCascadeEntities';

			foreach (glob($badFixturesDir . '/*.php') ?: [] as $file) {
				require_once $file;
			}

			$configuration = new Configuration();
			$configuration->setEntityPath($badFixturesDir);
			$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\BadCascadeEntities');
			$configuration->setUseMetadataCache(false);

			$entityStore = new EntityStore($configuration);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/carries an .@Orm\\\\Cascade. annotation with a .remove. operation on an .@Orm\\\\InverseOf. property/');

			$entityStore->getMetadata(RelParentWithBadCascadeEntity::class);
		}
	}
