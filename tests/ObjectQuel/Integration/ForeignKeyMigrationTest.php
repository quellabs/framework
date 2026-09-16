<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Sculpt\Helpers\QuelMigrationBuilder;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderRemovedEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarActionEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarEntity;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * MakeMigrationsCommand's underlying machinery: ForeignKeyComparator (the
	 * FK-vs-schema diff) and QuelMigrationBuilder (code generation + the
	 * create-everything-then-addForeignKey ordering).
	 *
	 * Deliberately scoped to just the foreign-key-specific diff/emission logic —
	 * column-level diffing is SchemaComparator's job and isn't covered here. Each
	 * scenario below hand-assembles the EntityChangeSet QuelMigrationBuilder
	 * expects, using real metadata (EntityStore) and a real foreign-key diff
	 * (ForeignKeyComparator against a live in-memory SQLite table) rather than
	 * mocks, so this exercises the actual code paths end to end.
	 */
	class ForeignKeyMigrationTest extends TestCase {
		use FkTestSupport;

		private DatabaseAdapter $adapter;
		private EntityStore $entityStore;
		private ForeignKeyComparator $comparator;
		private QuelMigrationBuilder $builder;

		protected function setUp(): void {
			$this->adapter = $this->makeSqliteAdapter();
			$this->entityStore = $this->makeFkEntityStore();
			$this->comparator = new ForeignKeyComparator($this->adapter, $this->entityStore, new PlatformCapabilities($this->adapter));
			$this->builder = new QuelMigrationBuilder($this->adapter, sys_get_temp_dir(), new PlatformCapabilities($this->adapter));
		}

		/**
		 * Invokes QuelMigrationBuilder's private buildMigrationContent() so the
		 * generated migration source can be asserted on directly, without writing
		 * a file to disk.
		 * @param array<string, array<string, mixed>> $allChanges
		 */
		private function buildMigrationContent(array $allChanges): string {
			$method = new \ReflectionMethod(QuelMigrationBuilder::class, 'buildMigrationContent');
			$method->setAccessible(true);
			return $method->invoke($this->builder, 'TestMigration', $allChanges);
		}

		private function emptyEntityChangeSet(): array {
			return [
				'added'    => [],
				'modified' => [],
				'deleted'  => [],
				'indexes'  => ['added' => [], 'modified' => [], 'deleted' => []],
			];
		}

		// -------------------------------------------------------------------------
		// Added
		// -------------------------------------------------------------------------

		public function testNewForeignKeyRelationEmitsAddForeignKeyWithTheRightConfig(): void {
			// customers table exists but has no constraint yet — orders table
			// exists with a bare customer_id column, matching what a project would
			// have before adopting @Orm\ForeignKey on an already-existing table.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute('CREATE TABLE fk_orders_scalar (id INTEGER PRIMARY KEY, customer_id INTEGER)');

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderScalarEntity::class);

			self::assertArrayHasKey('fk_fk_orders_scalar_customer_id', $fkDiff['added']);
			self::assertSame([], $fkDiff['modified']);
			self::assertSame([], $fkDiff['deleted']);

			$changes = $this->emptyEntityChangeSet();
			$changes['foreignKeys'] = $fkDiff;

			$content = $this->buildMigrationContent(['fk_orders_scalar' => $changes]);

			// Quel's `foreign key` clause never carries a constraint name —
			// naming is always derived by the DDL compiler, never author-supplied
			// (see objectquel-foreign-key-design.md, decision 1) — so this looks
			// identical regardless of platform, unlike Phinx's named-constraint option.
			self::assertStringContainsString(
				'alter fk_orders_scalar (add foreign key (customer_id) references fk_customers (id) on delete restrict on update no action)',
				$content
			);
			// down() must undo it.
			self::assertStringContainsString(
				'alter fk_orders_scalar (drop foreign key (customer_id))',
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Deleted
		// -------------------------------------------------------------------------

		public function testRemovedForeignKeyAnnotationEmitsADrop(): void {
			// The live table already has a real constraint (as if @Orm\ForeignKey
			// were removed from an entity that used to declare it); the entity
			// itself declares none.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute(
				'CREATE TABLE fk_orders_removed (' .
				'id INTEGER PRIMARY KEY, customer_id INTEGER, ' .
				'FOREIGN KEY (customer_id) REFERENCES fk_customers(id) ON DELETE RESTRICT' .
				')'
			);

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderRemovedEntity::class);

			self::assertSame([], $fkDiff['added']);
			self::assertSame([], $fkDiff['modified']);
			self::assertArrayHasKey('fk_fk_orders_removed_customer_id', $fkDiff['deleted']);

			$changes = $this->emptyEntityChangeSet();
			$changes['foreignKeys'] = $fkDiff;

			$content = $this->buildMigrationContent(['fk_orders_removed' => $changes]);

			self::assertStringContainsString(
				'alter fk_orders_removed (drop foreign key (customer_id))',
				$content
			);
			// down() must restore it.
			self::assertStringContainsString(
				'alter fk_orders_removed (add foreign key (customer_id) references fk_customers (id) on delete restrict on update no action)',
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Modified
		// -------------------------------------------------------------------------

		public function testModifiedForeignKeyActionEmitsADropAndRecreateWithTheNewRule(): void {
			// The live constraint already exists under the exact name this
			// generator would produce, but with the *old* rule (RESTRICT/NO
			// ACTION) — as if @Orm\ForeignKeyAction was just added/changed to
			// CASCADE/RESTRICT on FkOrderScalarActionEntity since the last run.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute(
				'CREATE TABLE fk_orders_scalar_action (' .
				'id INTEGER PRIMARY KEY, customer_id INTEGER, ' .
				'CONSTRAINT fk_fk_orders_scalar_action_customer_id FOREIGN KEY (customer_id) ' .
				'REFERENCES fk_customers(id) ON DELETE RESTRICT ON UPDATE NO ACTION' .
				')'
			);

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderScalarActionEntity::class);

			self::assertSame([], $fkDiff['added']);
			self::assertSame([], $fkDiff['deleted']);
			self::assertArrayHasKey('fk_fk_orders_scalar_action_customer_id', $fkDiff['modified']);

			$modified = $fkDiff['modified']['fk_fk_orders_scalar_action_customer_id'];
			self::assertSame('RESTRICT', $modified['database']->onDelete);
			self::assertSame('NO ACTION', $modified['database']->onUpdate);
			self::assertSame('CASCADE', $modified['entity']->onDelete);
			self::assertSame('RESTRICT', $modified['entity']->onUpdate);

			$changes = $this->emptyEntityChangeSet();
			$changes['foreignKeys'] = $fkDiff;

			$content = $this->buildMigrationContent(['fk_orders_scalar_action' => $changes]);

			// up() drops the old constraint and adds the new one with the entity's
			// rule, folded into one combined alter statement.
			self::assertStringContainsString(
				'alter fk_orders_scalar_action (drop foreign key (customer_id), ' .
				'add foreign key (customer_id) references fk_customers (id) on delete cascade on update restrict)',
				$content
			);

			// down() must restore the database's original rule, not just repeat
			// the entity's rule under a different name — invertForeignKeyModifications()
			// swaps entity/database, so this is the regression check that the swap
			// actually happened rather than being a no-op.
			$upPos = strpos($content, 'public function up');
			$downPos = strpos($content, 'public function down');
			self::assertNotFalse($upPos);
			self::assertNotFalse($downPos);

			$downBody = substr($content, $downPos);
			self::assertStringContainsString(
				'alter fk_orders_scalar_action (drop foreign key (customer_id), ' .
				'add foreign key (customer_id) references fk_customers (id) on delete restrict on update no action)',
				$downBody
			);
		}

		// -------------------------------------------------------------------------
		// Cascade is fully independent of the generated constraint
		// -------------------------------------------------------------------------

		public function testCascadePresentAlongsideForeignKeyDoesNotAffectTheGeneratedConstraint(): void {
			// FkOrderEntity has ManyToOne + Cascade on the relation property and
			// ForeignKey/ForeignKeyAction on its scalar backing column. This is pure
			// metadata resolution — no live table needs to exist — and proves the
			// generated constraint comes entirely from ForeignKey/ForeignKeyAction,
			// exactly as it would if Cascade weren't declared at all (compare
			// FkOrderScalarActionEntity's ForeignKeyAction(onDelete="CASCADE") above,
			// which has no Cascade, ManyToOne, or object relation anywhere).
			$definitions = $this->comparator->getEntityForeignKeys(FkOrderEntity::class);

			self::assertArrayHasKey('fk_fk_orders_customer_id', $definitions);

			$definition = $definitions['fk_fk_orders_customer_id'];
			self::assertSame(['customer_id'], $definition->columns);
			self::assertSame('fk_customers', $definition->referencedTable);
			self::assertSame(['id'], $definition->referencedColumns);
			self::assertSame('CASCADE', $definition->onDelete);
			// onUpdate was never declared on FkOrderEntity's ForeignKeyAction —
			// the plain annotation default, unaffected by Cascade being present.
			self::assertSame('NO ACTION', $definition->onUpdate);
		}

		// -------------------------------------------------------------------------
		// Determinism
		// -------------------------------------------------------------------------

		public function testUnchangedForeignKeyProducesAnEmptyDiffOnASecondRun(): void {
			// The live schema already matches exactly what FkOrderScalarEntity
			// declares — including the deterministic constraint name and the rules
			// this same generator would have produced. Re-running the diff must be
			// a no-op, or every regeneration would spuriously drop-and-recreate the
			// constraint.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute(
				'CREATE TABLE fk_orders_scalar (' .
				'id INTEGER PRIMARY KEY, customer_id INTEGER, ' .
				'FOREIGN KEY (customer_id) REFERENCES fk_customers(id) ON DELETE RESTRICT ON UPDATE NO ACTION' .
				')'
			);

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderScalarEntity::class);

			self::assertSame(['added' => [], 'modified' => [], 'deleted' => []], $fkDiff);
		}

		// -------------------------------------------------------------------------
		// Ordering
		// -------------------------------------------------------------------------

		/**
		 * Uses a platform that supports named foreign keys (unlike this
		 * file's shared sqlite-backed $this->builder) — on sqlite, a new
		 * table's own foreign key is embedded directly in its `create`
		 * statement instead (see
		 * testTwoNewCrossReferencingTablesEachEmbedTheirOwnForeignKeyInline()
		 * below), so there's no separate add-foreign-key statement to order
		 * against the two creates in the first place.
		 */
		public function testTwoNewCrossReferencingTablesEmitBothCreatesBeforeEitherAddForeignKey(): void {
			// Neither table exists yet in this fresh in-memory database.
			$customerMetadata = $this->entityStore->getMetadata(FkCustomerEntity::class);
			$orderMetadata = $this->entityStore->getMetadata(FkOrderScalarEntity::class);

			$allChanges = [
				'fk_customers'     => array_merge($this->emptyEntityChangeSet(), [
					'table_not_exists' => true,
					'added'             => $customerMetadata->getColumnDefinitionsForSchema(),
					'foreignKeys'       => ['added' => [], 'modified' => [], 'deleted' => []],
				]),
				'fk_orders_scalar' => array_merge($this->emptyEntityChangeSet(), [
					'table_not_exists' => true,
					'added'             => $orderMetadata->getColumnDefinitionsForSchema(),
					'foreignKeys'       => ['added' => $this->comparator->getEntityForeignKeys(FkOrderScalarEntity::class), 'modified' => [], 'deleted' => []],
				]),
			];

			$builder = new QuelMigrationBuilder($this->adapter, sys_get_temp_dir(), new FakePlatformCapabilities('mysql'));
			$method = new \ReflectionMethod(QuelMigrationBuilder::class, 'buildMigrationContent');
			$method->setAccessible(true);
			$content = $method->invoke($builder, 'TestMigration', $allChanges);

			$customersCreatePos = strpos($content, 'create fk_customers (');
			$ordersCreatePos = strpos($content, 'create fk_orders_scalar (');
			$addForeignKeyPos = strpos($content, 'add foreign key (');

			self::assertNotFalse($customersCreatePos);
			self::assertNotFalse($ordersCreatePos);
			self::assertNotFalse($addForeignKeyPos);

			// Both create statements appear before the add-foreign-key statement —
			// no dependency-ordering algorithm needed between the two tables
			// themselves, since neither create() carries an inline FK.
			self::assertLessThan($addForeignKeyPos, $customersCreatePos);
			self::assertLessThan($addForeignKeyPos, $ordersCreatePos);
		}

		/**
		 * The sqlite counterpart to the test above: SQLite's ALTER TABLE
		 * rejects adding a foreign key outright, even to a table this same
		 * migration just created, so each new table's own foreign key is
		 * embedded directly in its own `create` statement instead — no
		 * add-foreign-key statement, and no cross-table ordering concern,
		 * at all.
		 */
		public function testTwoNewCrossReferencingTablesEachEmbedTheirOwnForeignKeyInline(): void {
			$customerMetadata = $this->entityStore->getMetadata(FkCustomerEntity::class);
			$orderMetadata = $this->entityStore->getMetadata(FkOrderScalarEntity::class);

			$allChanges = [
				'fk_customers'     => array_merge($this->emptyEntityChangeSet(), [
					'table_not_exists' => true,
					'added'             => $customerMetadata->getColumnDefinitionsForSchema(),
					'foreignKeys'       => ['added' => [], 'modified' => [], 'deleted' => []],
				]),
				'fk_orders_scalar' => array_merge($this->emptyEntityChangeSet(), [
					'table_not_exists' => true,
					'added'             => $orderMetadata->getColumnDefinitionsForSchema(),
					'foreignKeys'       => ['added' => $this->comparator->getEntityForeignKeys(FkOrderScalarEntity::class), 'modified' => [], 'deleted' => []],
				]),
			];

			// $this->builder is constructed (in setUp()) with a real sqlite
			// adapter, where supportsNamedForeignKeys() is false.
			$content = $this->buildMigrationContent($allChanges);

			$this->assertStringContainsString('create fk_orders_scalar (', $content);
			$this->assertStringContainsString('foreign key (customer_id) references fk_customers (id)', $content);
			$this->assertStringNotContainsString('add foreign key', $content);
		}
	}
