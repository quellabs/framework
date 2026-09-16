<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\Sculpt\Helpers\QuelMigrationBuilder;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Unit-style coverage for QuelMigrationBuilder: given hand-crafted
	 * $allChanges shapes (the same structure EntitySchemaAnalyzer::
	 * analyzeEntityChanges() produces), assert the generated Quel statement
	 * text — see Phase 5 of objectquel-migrations-implementation-plan.md.
	 *
	 * Uses a real in-memory SQLite connection (see FkTestSupport) rather
	 * than mocking DatabaseAdapter, since buildAddColumnOp()'s backfill
	 * logic and the primary-key merge both run a real query against the
	 * connection (row count / getPrimaryKeyColumns()).
	 */
	class QuelMigrationBuilderTest extends TestCase {
		use FkTestSupport;

		private DatabaseAdapter $adapter;
		private QuelMigrationBuilder $builder;

		protected function setUp(): void {
			$this->adapter = $this->makeSqliteAdapter();
			$this->builder = new QuelMigrationBuilder($this->adapter, sys_get_temp_dir(), new PlatformCapabilities($this->adapter));
		}

		/**
		 * Invokes the private buildMigrationContent() so the generated
		 * source can be asserted on directly, without writing a file.
		 * @param array<string, array<string, mixed>> $allChanges
		 */
		private function buildMigrationContent(array $allChanges): string {
			$method = new \ReflectionMethod(QuelMigrationBuilder::class, 'buildMigrationContent');
			$method->setAccessible(true);
			return $method->invoke($this->builder, 'TestMigration', $allChanges);
		}

		/**
		 * @return array{added: array, modified: array, deleted: array, indexes: array{added: array, modified: array, deleted: array}, foreignKeys: array{added: array, modified: array, deleted: array}}
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

		private function baseColumn(array $overrides = []): ColumnDefinition {
			$merged = array_merge([
				'type'        => 'string',
				'php_type'    => 'string',
				'limit'       => 100,
				'default'     => null,
				'nullable'    => false,
				'precision'   => null,
				'scale'       => null,
				'unsigned'    => false,
				'generated'   => null,
				'identity'    => false,
				'primary_key' => false,
				'values'      => null,
			], $overrides);

			return new ColumnDefinition(...$merged);
		}

		private function baseForeignKey(array $overrides = []): ForeignKeyDefinition {
			$merged = array_merge([
				'columns'           => ['customer_id'],
				'referencedTable'   => 'customers',
				'referencedColumns' => ['id'],
				'onDelete'          => 'RESTRICT',
				'onUpdate'          => 'NO ACTION',
			], $overrides);

			return new ForeignKeyDefinition(...$merged);
		}

		// -------------------------------------------------------------------------
		// New table
		// -------------------------------------------------------------------------

		public function testNewTableEmitsOneCreateStatementAndDestroyOnDown(): void {
			$changes = $this->emptyChangeSet();
			$changes['table_not_exists'] = true;
			$changes['added'] = [
				'id'      => $this->baseColumn(['type' => 'integer', 'limit' => null, 'identity' => true, 'primary_key' => true]),
				'message' => $this->baseColumn(['limit' => 255]),
			];

			$content = $this->buildMigrationContent(['posts' => $changes]);

			$this->assertStringContainsString(
				"\$this->query('create posts (id = integer identity, message = string(255), primary key (id))');",
				$content
			);
			$this->assertStringContainsString("\$this->query('destroy posts');", $content);
		}

		public function testNewTableWithAnAddedIndexEmbedsItInTheCreateStatement(): void {
			$changes = $this->emptyChangeSet();
			$changes['table_not_exists'] = true;
			$changes['added'] = ['email' => $this->baseColumn(['limit' => 255])];
			$changes['indexes']['added'] = ['idx_email' => ['columns' => ['email'], 'type' => 'INDEX', 'unique' => false]];

			$content = $this->buildMigrationContent(['users' => $changes]);

			$this->assertStringContainsString(
				"\$this->query('create users (email = string(255), index idx_email (email))');",
				$content
			);
			// Dropping the table on down() takes its indexes with it — no
			// separate index-drop statement is needed.
			$this->assertStringContainsString("\$this->query('destroy users');", $content);
			$this->assertStringNotContainsString('idx_email on', $content);
		}

		// -------------------------------------------------------------------------
		// Added columns on an existing table
		// -------------------------------------------------------------------------

		public function testNullableAddedColumnNeedsNoBackfill(): void {
			$this->adapter->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
			$this->adapter->execute('INSERT INTO orders (id) VALUES (1)');

			$changes = $this->emptyChangeSet();
			$changes['added'] = ['note' => $this->baseColumn(['nullable' => true])];

			$content = $this->buildMigrationContent(['orders' => $changes]);

			$this->assertStringContainsString("\$this->query('alter orders (add note = string(100) nullable)');", $content);
			$this->assertStringContainsString("\$this->query('alter orders (drop note)');", $content);
		}

		public function testNonNullableAddedColumnOnAnEmptyTableNeedsNoBackfill(): void {
			$this->adapter->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

			$changes = $this->emptyChangeSet();
			$changes['added'] = ['status' => $this->baseColumn(['limit' => 20])];

			$content = $this->buildMigrationContent(['orders' => $changes]);

			$this->assertStringContainsString("\$this->query('alter orders (add status = string(20))');", $content);
		}

		public function testNonNullableAddedColumnOnAPopulatedTableWithADefaultGetsBackfilled(): void {
			$this->adapter->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
			$this->adapter->execute('INSERT INTO orders (id) VALUES (1)');

			$changes = $this->emptyChangeSet();
			$changes['added'] = ['status' => $this->baseColumn(['limit' => 20, 'default' => 'pending'])];

			$content = $this->buildMigrationContent(['orders' => $changes]);

			$this->assertStringContainsString(
				"\$this->query('alter orders (add status = string(20) backfill \\'pending\\')');",
				$content
			);
			// down() is unaffected by backfill — plain drop, same as any other added column.
			$this->assertStringContainsString("\$this->query('alter orders (drop status)');", $content);
		}

		public function testNonNullableAddedColumnOnAPopulatedTableWithNoDefaultThrows(): void {
			$this->adapter->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
			$this->adapter->execute('INSERT INTO orders (id) VALUES (1)');

			$changes = $this->emptyChangeSet();
			$changes['added'] = ['status' => $this->baseColumn(['limit' => 20])];

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("orders.status");

			$this->buildMigrationContent(['orders' => $changes]);
		}

		public function testBackfillValueContainingAQuoteIsEscaped(): void {
			$this->adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute('INSERT INTO customers (id) VALUES (1)');

			$changes = $this->emptyChangeSet();
			$changes['added'] = ['name' => $this->baseColumn(['limit' => 100, 'default' => "O'Brien"])];

			$content = $this->buildMigrationContent(['customers' => $changes]);

			$this->assertStringContainsString("backfill \\'O\\\\\\'Brien\\'", $content);
		}

		/**
		 * PrimaryKeyComparator (not QuelMigrationBuilder) is responsible for
		 * diffing the entity's declared key against the live table's actual
		 * one — this test supplies that diff result directly, the same way
		 * every other scenario in this file supplies its 'indexes'/
		 * 'foreignKeys' diff directly, rather than exercising a live
		 * database.
		 */
		public function testAddedPrimaryKeyColumnIsCombinedWithTheAddedColumnInOneAlterStatement(): void {
			$changes = $this->emptyChangeSet();
			$changes['added'] = ['tag_id' => $this->baseColumn(['type' => 'integer', 'limit' => null, 'primary_key' => true])];
			$changes['primaryKey'] = ['action' => 'set', 'columns' => ['post_id', 'tag_id'], 'from' => ['post_id']];

			$content = $this->buildMigrationContent(['bridge' => $changes]);

			$this->assertStringContainsString(
				"\$this->query('alter bridge (add tag_id = integer, primary key (post_id, tag_id))');",
				$content
			);
			// down() restores the original, single-column key before
			// dropping the added column — freeing tag_id from the key
			// before removing it outright, rather than relying on the
			// database to adjust the key implicitly.
			$this->assertStringContainsString(
				"\$this->query('alter bridge (primary key (post_id), drop tag_id)');",
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Modified / deleted columns
		// -------------------------------------------------------------------------

		public function testModifiedColumnEmitsRetypeOnUpAndTheOriginalDefinitionOnDown(): void {
			$changes = $this->emptyChangeSet();
			$changes['modified'] = [
				'price' => [
					'from'    => $this->baseColumn(['type' => 'integer', 'limit' => null]),
					'to'      => $this->baseColumn(['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'limit' => null]),
					'changes' => [],
				],
			];

			$content = $this->buildMigrationContent(['products' => $changes]);

			$this->assertStringContainsString("\$this->query('alter products (retype price = decimal(10,2))');", $content);
			$this->assertStringContainsString("\$this->query('alter products (retype price = integer)');", $content);
		}

		public function testDeletedColumnEmitsDropOnUpAndAddOnDown(): void {
			$changes = $this->emptyChangeSet();
			$changes['deleted'] = ['legacy_flag' => $this->baseColumn(['type' => 'boolean', 'limit' => null])];

			$content = $this->buildMigrationContent(['users' => $changes]);

			$this->assertStringContainsString("\$this->query('alter users (drop legacy_flag)');", $content);
			$this->assertStringContainsString("\$this->query('alter users (add legacy_flag = boolean)');", $content);
		}

		// -------------------------------------------------------------------------
		// Enum
		// -------------------------------------------------------------------------

		public function testEnumColumnIsRenderedVerbatimRegardlessOfPlatform(): void {
			$changes = $this->emptyChangeSet();
			$changes['added'] = ['status' => $this->baseColumn(['type' => 'enum', 'limit' => null, 'values' => ['active', 'inactive']])];

			$content = $this->buildMigrationContent(['posts' => $changes]);

			$this->assertStringContainsString(
				"\$this->query('alter posts (add status = enum(\\'active\\', \\'inactive\\'))');",
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Indexes on an existing table
		// -------------------------------------------------------------------------

		public function testAddedUniqueIndexIsFoldedIntoTheAlterStatement(): void {
			$changes = $this->emptyChangeSet();
			$changes['indexes']['added'] = ['idx_email' => ['columns' => ['email'], 'type' => 'UNIQUE', 'unique' => true]];

			$content = $this->buildMigrationContent(['users' => $changes]);

			$this->assertStringContainsString("\$this->query('alter users (add unique index idx_email (email))');", $content);
			$this->assertStringContainsString("\$this->query('alter users (drop index idx_email)');", $content);
		}

		public function testModifiedIndexEmitsDropThenAddOnUpAndTheInverseOnDownWithinOneAlterStatement(): void {
			$changes = $this->emptyChangeSet();
			$changes['indexes']['modified'] = [
				'idx_name' => [
					'entity'   => ['columns' => ['first_name', 'last_name'], 'type' => 'INDEX', 'unique' => false],
					'database' => ['columns' => ['first_name'], 'type' => 'INDEX', 'unique' => false],
				],
			];

			$content = $this->buildMigrationContent(['users' => $changes]);

			$this->assertStringContainsString(
				"\$this->query('alter users (drop index idx_name, add index idx_name (first_name, last_name))');",
				$content
			);
			$this->assertStringContainsString(
				"\$this->query('alter users (drop index idx_name, add index idx_name (first_name))');",
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Foreign keys
		// -------------------------------------------------------------------------

		public function testAddedForeignKeyEmitsAlterWithAddForeignKeyAndItsInverseOnDown(): void {
			$changes = $this->emptyChangeSet();
			$changes['foreignKeys']['added'] = [
				'fk_orders_customer_id' => $this->baseForeignKey(['onDelete' => 'CASCADE', 'onUpdate' => 'RESTRICT']),
			];

			$content = $this->buildMigrationContent(['orders' => $changes]);

			$this->assertStringContainsString(
				"\$this->query('alter orders (add foreign key (customer_id) references customers (id) on delete cascade on update restrict)');",
				$content
			);
			$this->assertStringContainsString("\$this->query('alter orders (drop foreign key (customer_id))');", $content);
		}

		public function testDeletedForeignKeyEmitsDropOnUpAndAddOnDown(): void {
			$changes = $this->emptyChangeSet();
			$changes['foreignKeys']['deleted'] = [
				'fk_orders_customer_id' => $this->baseForeignKey(['onDelete' => 'NO ACTION', 'onUpdate' => 'NO ACTION']),
			];

			$content = $this->buildMigrationContent(['orders' => $changes]);

			$this->assertStringContainsString("\$this->query('alter orders (drop foreign key (customer_id))');", $content);
			$this->assertStringContainsString(
				"\$this->query('alter orders (add foreign key (customer_id) references customers (id) on delete no action on update no action)');",
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Ordering
		// -------------------------------------------------------------------------

		/**
		 * Foreign keys always compile after every table/column/index
		 * change, regardless of table iteration order, so a table a new FK
		 * references is guaranteed to already exist.
		 */
		public function testForeignKeysCompileAfterTableCreationRegardlessOfDeclarationOrder(): void {
			$ordersChanges = $this->emptyChangeSet();
			$ordersChanges['table_not_exists'] = true;
			$ordersChanges['added'] = ['id' => $this->baseColumn(['type' => 'integer', 'limit' => null, 'identity' => true, 'primary_key' => true])];
			$ordersChanges['foreignKeys']['added'] = [
				'fk_orders_customer_id' => $this->baseForeignKey(),
			];

			$content = $this->buildMigrationContent(['orders' => $ordersChanges]);

			$createPos = strpos($content, "create orders");
			$fkPos = strpos($content, "add foreign key");

			$this->assertNotFalse($createPos);
			$this->assertNotFalse($fkPos);
			$this->assertLessThan($fkPos, $createPos);
		}

		public function testEmptyChangesReturnsAFailureResultWithoutWritingAFile(): void {
			$builder = new QuelMigrationBuilder($this->adapter, sys_get_temp_dir() . '/oq_qmb_' . uniqid());
			$result = $builder->generateMigrationFile([]);

			$this->assertFalse($result['success']);
			$this->assertSame('No changes detected. Migration file not created.', $result['message']);
		}

		public function testGenerateMigrationFileWritesAWrappedMigrationClassToDisk(): void {
			$dir = sys_get_temp_dir() . '/oq_qmb_' . uniqid();
			$builder = new QuelMigrationBuilder($this->adapter, $dir);

			$changes = $this->emptyChangeSet();
			$changes['table_not_exists'] = true;
			$changes['added'] = ['id' => $this->baseColumn(['type' => 'integer', 'limit' => null, 'identity' => true, 'primary_key' => true])];

			$result = $builder->generateMigrationFile(['widgets' => $changes]);

			$this->assertTrue($result['success']);
			$this->assertFileExists($result['path']);
			$this->assertMatchesRegularExpression('/^\d{14}_QuelSchemaMigration\d{14}\.php$/', basename($result['path']));

			$source = file_get_contents($result['path']);
			$this->assertStringContainsString('extends AbstractMigration', $source);
			$this->assertStringContainsString('use Quellabs\ObjectQuel\Migration\AbstractMigration;', $source);
			$this->assertStringContainsString('public function up(): void {', $source);
			$this->assertStringContainsString('public function down(): void {', $source);

			// The generated file must itself be syntactically valid PHP.
			$this->assertSame(0, self::lintPhpFile($result['path']));

			unlink($result['path']);
			rmdir($dir);
		}

		private static function lintPhpFile(string $path): int {
			exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
			return $exitCode;
		}
	}
