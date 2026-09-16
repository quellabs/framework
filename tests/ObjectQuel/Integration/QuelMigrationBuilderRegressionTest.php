<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Sqlite;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntitySchemaAnalyzer;
	use Quellabs\ObjectQuel\Sculpt\Helpers\QuelMigrationBuilder;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\MigRegCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\MigRegLineItemEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\MigRegOrderEntity;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Regression-checks make:migrations' full pipeline — EntitySchemaAnalyzer's
	 * real diff (not hand-crafted arrays, unlike QuelMigrationBuilderTest) fed
	 * into QuelMigrationBuilder — against one small, combined fixture entity
	 * set covering every facet Phase 7 of
	 * objectquel-migrations-implementation-plan.md asks for together: a new
	 * table with PK+FK, an added/dropped/retyped column, an added
	 * non-nullable column with backfill on a populated table, an enum
	 * column, an added and a deleted index, and an added foreign key. FK
	 * add/modify/delete's own deeper coverage lives in
	 * ForeignKeyMigrationTest instead — this test's job is proving all of
	 * the above work correctly *together* in one real diff-to-generation
	 * pass, not re-covering each facet exhaustively on its own.
	 */
	class QuelMigrationBuilderRegressionTest extends TestCase {
		use FkTestSupport;

		private ?string $dbFile = null;

		protected function tearDown(): void {
			// @-suppressed: on Windows, SQLite's own file lock can still be
			// held briefly after the last PDO connection to it goes out of
			// scope, racing this delete — harmless, since it's a per-test
			// temp file nothing else depends on; the OS temp directory
			// cleans it up regardless.
			if ($this->dbFile !== null && file_exists($this->dbFile)) {
				@unlink($this->dbFile);
			}
		}

		/**
		 * A *file-based* SQLite connection, deliberately not FkTestSupport's
		 * :memory: one — DatabaseAdapter::getColumns() (still Phinx-backed;
		 * see objectquel-phinx-removal-plan.md) opens its own separate PDO
		 * connection from the CakePHP connection's own config. For
		 * `:memory:` that's a distinct, empty database, so schema-diffing
		 * tests that rely on getColumns() (this one does, via
		 * EntitySchemaAnalyzer/SchemaComparator) never see anything created
		 * through the CakePHP connection. A real file both connections open
		 * doesn't have that problem — same fix
		 * AlterTableBackfillSqliteTest's own docblock documents for the
		 * identical issue.
		 */
		private function makeFileBasedSqliteAdapter(): DatabaseAdapter {
			$this->dbFile = sys_get_temp_dir() . '/oq_qmb_regression_' . str_replace('.', '', uniqid('', true)) . '.sqlite';

			$connection = new Connection([
				'driver'   => Sqlite::class,
				'database' => $this->dbFile,
			]);

			return new DatabaseAdapter($connection);
		}

		public function testCombinedDiffProducesTheExpectedMigrationForEveryFacet(): void {
			$adapter = $this->makeFileBasedSqliteAdapter();
			$entityStore = $this->makeFkEntityStore();
			$platform = new PlatformCapabilities($adapter);

			// The "before" schema: mig_reg_customers already matches its
			// entity exactly (no changes expected there). mig_reg_orders is
			// the pre-existing table the entity's changes are diffed
			// against — legacy_note will be dropped, price retyped,
			// idx_mig_reg_orders_price_old will be dropped (not declared on
			// the entity), and status/priority don't exist yet (added).
			// mig_reg_line_items doesn't exist at all yet (new table).
			$adapter->execute('CREATE TABLE mig_reg_customers (id INTEGER PRIMARY KEY NOT NULL)');
			$adapter->execute(
				'CREATE TABLE mig_reg_orders (' .
				'id INTEGER PRIMARY KEY NOT NULL, ' .
				'customer_id INTEGER NOT NULL, ' .
				'legacy_note TEXT, ' .
				'price INTEGER NOT NULL, ' .
				'quantity INTEGER NOT NULL' .
				')'
			);
			$adapter->execute('CREATE INDEX idx_mig_reg_orders_price_old ON mig_reg_orders (price)');

			// Existing rows — required for the added `status` column
			// (non-nullable, with a declared default) to need backfilling.
			$adapter->execute("INSERT INTO mig_reg_orders (id, customer_id, legacy_note, price, quantity) VALUES (1, 1, 'note', 100, 5)");
			$adapter->execute("INSERT INTO mig_reg_orders (id, customer_id, legacy_note, price, quantity) VALUES (2, 1, 'note', 200, 3)");

			$analyzer = new EntitySchemaAnalyzer($adapter, $entityStore, $platform);
			$allChanges = $analyzer->analyzeEntityChanges([
				MigRegCustomerEntity::class => 'mig_reg_customers',
				MigRegOrderEntity::class    => 'mig_reg_orders',
				MigRegLineItemEntity::class => 'mig_reg_line_items',
			]);

			// The unchanged table contributes nothing to the diff.
			$this->assertArrayNotHasKey('mig_reg_customers', $allChanges);

			// --- mig_reg_orders: column-level facets -----------------------------------

			$orderChanges = $allChanges['mig_reg_orders'];
			$this->assertArrayHasKey('status', $orderChanges['added']);
			$this->assertArrayHasKey('priority', $orderChanges['added']);
			$this->assertArrayHasKey('legacy_note', $orderChanges['deleted']);
			$this->assertArrayHasKey('price', $orderChanges['modified']);
			$this->assertSame('decimal', $orderChanges['modified']['price']['to']->type);

			// --- mig_reg_orders: index facets -------------------------------------------

			$this->assertArrayHasKey('idx_mig_reg_orders_quantity', $orderChanges['indexes']['added']);
			$this->assertArrayHasKey('idx_mig_reg_orders_price_old', $orderChanges['indexes']['deleted']);

			// --- mig_reg_orders: foreign key facet --------------------------------------

			$this->assertArrayHasKey('fk_mig_reg_orders_customer_id', $orderChanges['foreignKeys']['added']);

			// --- mig_reg_line_items: new table facet ------------------------------------

			$lineItemChanges = $allChanges['mig_reg_line_items'];
			$this->assertTrue($lineItemChanges['table_not_exists']);
			$this->assertArrayHasKey('fk_mig_reg_line_items_order_id', $lineItemChanges['foreignKeys']['added']);

			// --- generation: every facet must round-trip into Quel text ----------------

			$builder = new QuelMigrationBuilder($adapter, sys_get_temp_dir(), $platform);
			$method = new \ReflectionMethod(QuelMigrationBuilder::class, 'buildMigrationContent');
			$method->setAccessible(true);
			$content = $method->invoke($builder, 'RegressionTestMigration', $allChanges);

			// New table with PK+FK. On SQLite (this test's fixture), the FK
			// is embedded directly in `create` rather than a separate
			// `alter ... add foreign key` — SQLite's ALTER TABLE rejects
			// adding one outright, even to a table just created.
			$this->assertStringContainsString(
				'create mig_reg_line_items (id = unsigned integer identity, order_id = integer, primary key (id), ' .
				'foreign key (order_id) references mig_reg_orders (id) on delete restrict on update no action)',
				$content
			);

			// Dropped/retyped columns, folded into one combined alter statement.
			$this->assertStringContainsString('drop legacy_note', $content);
			$this->assertStringContainsString('retype price = decimal(10,2)', $content);

			// Added non-nullable column with backfill. The Quel text's own
			// quotes are escaped (\') in $content, since this is the raw PHP
			// source — the generated file's addslashes() layer, not the
			// Quel text QuelToSQLAlter itself would eventually execute.
			$this->assertStringContainsString("add status = string(20) backfill \\'pending\\'", $content);

			// Added nullable enum column.
			$this->assertStringContainsString("add priority = enum(\\'low\\', \\'high\\') nullable", $content);

			// Index add/drop, folded into the same combined alter statement
			// as the column changes above.
			$this->assertStringContainsString('add index idx_mig_reg_orders_quantity (quantity)', $content);
			$this->assertStringContainsString('drop index idx_mig_reg_orders_price_old', $content);

			// Added foreign key.
			$this->assertStringContainsString(
				'add foreign key (customer_id) references mig_reg_customers (id) on delete restrict on update no action',
				$content
			);

			// The generated file itself must be valid, loadable PHP.
			$tmpFile = tempnam(sys_get_temp_dir(), 'oq_qmb_regression_');
			file_put_contents($tmpFile, $content);
			exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $lintOutput, $lintExitCode);
			unlink($tmpFile);
			$this->assertSame(0, $lintExitCode, implode("\n", $lintOutput));
		}
	}
