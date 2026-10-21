<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\Sculpt\Helpers\QuelMigrationBuilder;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * A primary-key change on a table still referenced by another table's
	 * live foreign key is rejected outright by every engine that enforces
	 * named foreign keys (MySQL/MariaDB, PostgreSQL, SQL Server) — this
	 * covers QuelMigrationBuilder's fix: find every such dependent constraint
	 * up front, drop it before the primary-key change, and restore it
	 * afterward in whatever shape it's actually supposed to end up in.
	 *
	 * Uses a mocked DatabaseAdapter (getTables()/getForeignKeys() stubbed to
	 * canned data) rather than a live connection, matching
	 * DatabaseAdapterForeignKeyPostgresTest's approach — only the generated
	 * Quel statement text is asserted on, mirroring QuelMigrationBuilderTest.
	 */
	class QuelMigrationBuilderForeignKeyDependencyTest extends TestCase {

		/**
		 * @param array<string, list<string>> $tableForeignKeyNames Table => constraint names it owns
		 * @param array<string, ForeignKeyDefinition> $foreignKeysByName Constraint name => live definition
		 */
		private function makeAdapter(array $tableForeignKeyNames, array $foreignKeysByName): DatabaseAdapter {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getTables')->willReturn(array_keys($tableForeignKeyNames));

			$adapter->method('getForeignKeys')->willReturnCallback(
				function (string $tableName) use ($tableForeignKeyNames, $foreignKeysByName) {
					$result = [];

					foreach ($tableForeignKeyNames[$tableName] ?? [] as $name) {
						$result[$name] = $foreignKeysByName[$name];
					}

					return $result;
				}
			);

			return $adapter;
		}

		private function buildMigrationContent(QuelMigrationBuilder $builder, array $allChanges): string {
			$method = new \ReflectionMethod(QuelMigrationBuilder::class, 'buildMigrationContent');
			$method->setAccessible(true);
			return $method->invoke($builder, 'TestMigration', $allChanges);
		}

		/**
		 * Splits the generated file into its up()/down() method bodies, so
		 * assertions can tell which direction a statement belongs to
		 * without relying on string position arithmetic.
		 * @return array{0: string, 1: string} [up body, down body]
		 */
		private function splitUpAndDown(string $content): array {
			$downPos = strpos($content, 'public function down()');
			self::assertNotFalse($downPos, 'Generated migration has no down() method');

			return [substr($content, 0, $downPos), substr($content, $downPos)];
		}

		/**
		 * @return array{added: array, modified: array, deleted: array, indexes: array{added: array, modified: array, deleted: array}, foreignKeys: array{added: array, modified: array, deleted: array}, primaryKey: array}
		 */
		private function emptyChangeSet(): array {
			return [
				'added'       => [],
				'modified'    => [],
				'deleted'     => [],
				'indexes'     => ['added' => [], 'modified' => [], 'deleted' => []],
				'foreignKeys' => ['added' => [], 'modified' => [], 'deleted' => []],
				'primaryKey'  => ['action' => null],
			];
		}

		/**
		 * The baseline case: 'orders' isn't otherwise part of this migration
		 * at all (no column/index/FK change of its own) — only 'customers'
		 * primary key is changing, and 'orders' happens to hold a live FK
		 * pointing at it.
		 */
		public function testUntouchedDependentForeignKeyIsDroppedBeforeAndRestoredAfterThePrimaryKeyChange(): void {
			$fk = new ForeignKeyDefinition(['customer_id'], 'customers', ['id'], 'RESTRICT', 'NO ACTION');
			$adapter = $this->makeAdapter(['orders' => ['fk_orders_customer_id']], ['fk_orders_customer_id' => $fk]);
			$builder = new QuelMigrationBuilder($adapter, sys_get_temp_dir(), new FakePlatformCapabilities('mysql'));

			$customersChanges = $this->emptyChangeSet();
			$customersChanges['primaryKey'] = ['action' => 'set', 'columns' => ['id', 'region'], 'from' => ['id']];

			[$up, $down] = $this->splitUpAndDown($this->buildMigrationContent($builder, ['customers' => $customersChanges]));

			$dropOp = "alter orders (drop foreign key (customer_id))";
			$addOp = "alter orders (add foreign key (customer_id) references customers (id) on delete restrict on update no action)";
			$pkSetOp = "alter customers (primary key (id, region))";
			$pkRevertOp = "alter customers (primary key (id))";

			// up(): dropped first, restored only after the key changes.
			self::assertLessThan(strpos($up, $pkSetOp), strpos($up, $dropOp));
			self::assertGreaterThan(strpos($up, $pkSetOp), strpos($up, $addOp));

			// down(): the exact reverse — dropped first, restored only after the key reverts.
			self::assertLessThan(strpos($down, $pkRevertOp), strpos($down, $dropOp));
			self::assertGreaterThan(strpos($down, $pkRevertOp), strpos($down, $addOp));
		}

		/**
		 * SQLite enforces foreign keys at write time, not as schema-level
		 * objects blocking a primary-key change, and has no ALTER TABLE
		 * support for foreign keys at all — this entire mechanism must be a
		 * no-op there.
		 */
		public function testNoDropOrRestoreStatementsAreEmittedOnSqlite(): void {
			$fk = new ForeignKeyDefinition(['customer_id'], 'customers', ['id'], 'RESTRICT', 'NO ACTION');
			$adapter = $this->makeAdapter(['orders' => ['fk_orders_customer_id']], ['fk_orders_customer_id' => $fk]);
			$builder = new QuelMigrationBuilder($adapter, sys_get_temp_dir(), new FakePlatformCapabilities('sqlite'));

			$customersChanges = $this->emptyChangeSet();
			$customersChanges['primaryKey'] = ['action' => 'set', 'columns' => ['id', 'region'], 'from' => ['id']];

			$content = $this->buildMigrationContent($builder, ['customers' => $customersChanges]);

			self::assertStringNotContainsString('orders', $content);
		}

		/**
		 * A dependent constraint that's also independently modified by its
		 * own table's diff must be dropped exactly once in up() — not twice
		 * from both this mechanism and the table's own diff — and restores
		 * in its new (entity-declared) shape, not the live one just dropped.
		 * down() undoes both: drops the new shape, then (after the primary
		 * key reverts) restores the original.
		 */
		public function testDependentForeignKeyAlsoBeingModifiedIsNotDoubleDroppedInUpAndRestoresInItsNewShape(): void {
			$liveFk = new ForeignKeyDefinition(['customer_id'], 'customers', ['id'], 'RESTRICT', 'NO ACTION');
			$newFk = new ForeignKeyDefinition(['customer_id'], 'customers', ['id'], 'CASCADE', 'NO ACTION');
			$adapter = $this->makeAdapter(['orders' => ['fk_orders_customer_id']], ['fk_orders_customer_id' => $liveFk]);
			$builder = new QuelMigrationBuilder($adapter, sys_get_temp_dir(), new FakePlatformCapabilities('mysql'));

			$customersChanges = $this->emptyChangeSet();
			$customersChanges['primaryKey'] = ['action' => 'set', 'columns' => ['id', 'region'], 'from' => ['id']];

			$ordersChanges = $this->emptyChangeSet();
			$ordersChanges['foreignKeys']['modified'] = [
				'fk_orders_customer_id' => ['database' => $liveFk, 'entity' => $newFk],
			];

			[$up, $down] = $this->splitUpAndDown(
				$this->buildMigrationContent($builder, ['customers' => $customersChanges, 'orders' => $ordersChanges])
			);

			// up(): exactly one drop (pass 0's), and the new (cascade) shape added — never the old one.
			self::assertSame(1, substr_count($up, 'drop foreign key (customer_id)'));
			self::assertStringContainsString('add foreign key (customer_id) references customers (id) on delete cascade on update no action', $up);
			self::assertStringNotContainsString('on delete restrict', $up);

			// down(): drops the new shape, restores the original (restrict) — never the cascade one.
			self::assertSame(1, substr_count($down, 'drop foreign key (customer_id)'));
			self::assertStringContainsString('add foreign key (customer_id) references customers (id) on delete restrict on update no action', $down);
			self::assertStringNotContainsString('on delete cascade', $down);
		}

		/**
		 * A dependent constraint that's also being deleted outright by its
		 * own table's diff must be dropped exactly once in up() — not twice
		 * — and never re-added there; down() still restores the original,
		 * but only after the primary key has reverted.
		 */
		public function testDependentForeignKeyAlsoBeingDeletedIsNotDoubleDroppedAndOnlyRestoredOnDown(): void {
			$liveFk = new ForeignKeyDefinition(['customer_id'], 'customers', ['id'], 'RESTRICT', 'NO ACTION');
			$adapter = $this->makeAdapter(['orders' => ['fk_orders_customer_id']], ['fk_orders_customer_id' => $liveFk]);
			$builder = new QuelMigrationBuilder($adapter, sys_get_temp_dir(), new FakePlatformCapabilities('mysql'));

			$customersChanges = $this->emptyChangeSet();
			$customersChanges['primaryKey'] = ['action' => 'set', 'columns' => ['id', 'region'], 'from' => ['id']];

			$ordersChanges = $this->emptyChangeSet();
			$ordersChanges['foreignKeys']['deleted'] = ['fk_orders_customer_id' => $liveFk];

			[$up, $down] = $this->splitUpAndDown(
				$this->buildMigrationContent($builder, ['customers' => $customersChanges, 'orders' => $ordersChanges])
			);

			self::assertSame(1, substr_count($up, 'drop foreign key (customer_id)'));
			self::assertStringNotContainsString('add foreign key (customer_id)', $up);

			self::assertStringNotContainsString('drop foreign key (customer_id)', $down);
			self::assertStringContainsString('add foreign key (customer_id) references customers (id) on delete restrict on update no action', $down);

			$pkRevertOp = "alter customers (primary key (id))";
			self::assertGreaterThan(strpos($down, $pkRevertOp), strpos($down, 'add foreign key (customer_id)'));
		}
	}
