<?php

	namespace Quellabs\ObjectQuel\Tests\SQLServer;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Sqlserver;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Sculpt\Helpers\QuelMigrationBuilder;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelCompositeEntity;

	/**
	 * Composite foreign-key behavior against a real SQL Server server —
	 * the live counterpart the composite-primary-keys review (finding 2)
	 * flagged as missing: DatabaseAdapterForeignKeySqlServerTest only
	 * exercises getForeignKeys() parsing against mocked sys.foreign_keys/
	 * sys.foreign_key_columns rows, and phpunit.sqlserver.xml's SQLite
	 * bootstrap cannot create a real T-SQL constraint at all.
	 *
	 * Self-contained like DatabaseAdapterForeignKeyMySqlTest — connects on
	 * its own using TEST_SQLSERVER_* environment variables and skips
	 * entirely (does not fail) when no server is reachable, rather than
	 * depending on phpunit.sqlserver.xml's bootstrap. Only creates/drops
	 * its own uniquely-prefixed tables.
	 *
	 * T-SQL's FOREIGN KEY clause has no RESTRICT keyword — NO ACTION is its
	 * behavioral equivalent (see ForeignKeyActionNormalizer) — so raw fixture
	 * DDL here uses NO ACTION, and the DSL/migration-generated RESTRICT case
	 * is asserted to read back as NO ACTION once introspected.
	 */
	class ForeignKeyCompositeSqlServerLiveTest extends TestCase {

		private ?DatabaseAdapter $adapter = null;
		private ?EntityManager $em = null;

		protected function setUp(): void {
			$host = getenv('TEST_SQLSERVER_HOST') ?: '127.0.0.1';
			$port = (int)(getenv('TEST_SQLSERVER_PORT') ?: 1433);
			$name = getenv('TEST_SQLSERVER_NAME') ?: 'tempdb';
			$user = getenv('TEST_SQLSERVER_USER') ?: 'sa';
			$pass = getenv('TEST_SQLSERVER_PASS') ?: '';

			$connectionConfig = [
				'driver'   => Sqlserver::class,
				'host'     => $host,
				'port'     => $port,
				'database' => $name,
				'username' => $user,
				'password' => $pass,
				'encoding' => 'utf8',
			];

			try {
				$connection = new Connection($connectionConfig);
				$connection->getDriver()->connect();
			} catch (\Throwable $e) {
				self::markTestSkipped('No live SQL Server reachable for this test: ' . $e->getMessage());
			}

			$this->adapter = new DatabaseAdapter($connection);

			$configuration = new Configuration();
			$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\Entities');
			$configuration->setEntityPath(__DIR__ . '/../Fixtures/Entities');
			$configuration->setProxyDir(sys_get_temp_dir() . '/objectquel-sqlserver-live-proxies');
			$this->em = new EntityManager($configuration, new Connection($connectionConfig));

			$this->dropFixtureTables();
		}

		protected function tearDown(): void {
			if ($this->adapter !== null) {
				$this->dropFixtureTables();
			}
		}

		private function dropFixtureTables(): void {
			$this->adapter->execute('IF OBJECT_ID(\'oq_fk_live_mssql_children\', \'U\') IS NOT NULL DROP TABLE oq_fk_live_mssql_children');
			$this->adapter->execute('IF OBJECT_ID(\'oq_fk_live_mssql_parents\', \'U\') IS NOT NULL DROP TABLE oq_fk_live_mssql_parents');
			$this->adapter->execute('IF OBJECT_ID(\'fk_orders_class_level_composite\', \'U\') IS NOT NULL DROP TABLE fk_orders_class_level_composite');
			$this->adapter->execute('IF OBJECT_ID(\'fk_composite_targets\', \'U\') IS NOT NULL DROP TABLE fk_composite_targets');
		}

		/**
		 * Ordered local/target column pairs and both rules read back
		 * correctly for a composite constraint via the real
		 * sys.foreign_keys/sys.foreign_key_columns catalog query — not
		 * the mocked rows DatabaseAdapterForeignKeySqlServerTest exercises.
		 */
		public function testGetForeignKeysReadsBackACompositeConstraintWithCorrectlyPairedColumnsAndActions(): void {
			$this->adapter->execute('CREATE TABLE oq_fk_live_mssql_parents (tenant_id INT, number VARCHAR(20), amount INT, PRIMARY KEY (tenant_id, number))');
			$this->adapter->execute(
				'CREATE TABLE oq_fk_live_mssql_children (' .
				'id INT IDENTITY PRIMARY KEY, order_tenant_id INT NOT NULL, order_number VARCHAR(20) NOT NULL, ' .
				'CONSTRAINT fk_oq_fk_live_mssql_children_order FOREIGN KEY (order_tenant_id, order_number) ' .
				'REFERENCES oq_fk_live_mssql_parents (tenant_id, number) ON DELETE CASCADE ON UPDATE NO ACTION' .
				')'
			);

			$foreignKeys = $this->adapter->getForeignKeys('oq_fk_live_mssql_children');

			self::assertArrayHasKey('fk_oq_fk_live_mssql_children_order', $foreignKeys);

			$fk = $foreignKeys['fk_oq_fk_live_mssql_children_order'];
			self::assertSame(['order_tenant_id', 'order_number'], $fk->columns);
			self::assertSame('oq_fk_live_mssql_parents', $fk->referencedTable);
			self::assertSame(['tenant_id', 'number'], $fk->referencedColumns);
			self::assertSame('CASCADE', $fk->onDelete);
			self::assertSame('NO ACTION', $fk->onUpdate);
		}

		/**
		 * The constraint actually enforces referential integrity (an
		 * orphan insert fails) and ON DELETE CASCADE actually removes the
		 * matching child row, exercised with a raw INSERT/DELETE that goes
		 * around the ORM entirely — mirroring
		 * DatabaseAdapterForeignKeyMySqlTest's equivalent single-column
		 * checks, here for the composite key.
		 */
		public function testCompositeForeignKeyEnforcesReferentialIntegrityAndCascadesOnDelete(): void {
			$this->adapter->execute('CREATE TABLE oq_fk_live_mssql_parents (tenant_id INT, number VARCHAR(20), amount INT, PRIMARY KEY (tenant_id, number))');
			$this->adapter->execute(
				'CREATE TABLE oq_fk_live_mssql_children (' .
				'id INT IDENTITY PRIMARY KEY, order_tenant_id INT NOT NULL, order_number VARCHAR(20) NOT NULL, ' .
				'CONSTRAINT fk_oq_fk_live_mssql_children_order FOREIGN KEY (order_tenant_id, order_number) ' .
				'REFERENCES oq_fk_live_mssql_parents (tenant_id, number) ON DELETE CASCADE ON UPDATE NO ACTION' .
				')'
			);

			$this->adapter->execute("INSERT INTO oq_fk_live_mssql_parents (tenant_id, number, amount) VALUES (1, 'A-1', 100)");

			$ok = $this->adapter->execute("INSERT INTO oq_fk_live_mssql_children (order_tenant_id, order_number) VALUES (1, 'A-1')");
			self::assertNotNull($ok);

			// Shares the first component with the parent row but not the second —
			// a first-component-only match must not satisfy the composite constraint.
			$orphan = $this->adapter->execute("INSERT INTO oq_fk_live_mssql_children (order_tenant_id, order_number) VALUES (1, 'A-2')");
			self::assertNull($orphan);
			self::assertStringContainsString('FOREIGN KEY', (string)$this->adapter->getLastErrorMessage());

			$result = $this->adapter->execute("DELETE FROM oq_fk_live_mssql_parents WHERE tenant_id = 1 AND number = 'A-1'");
			self::assertNotNull($result);

			$remaining = $this->adapter->execute("SELECT id FROM oq_fk_live_mssql_children WHERE order_tenant_id = 1 AND order_number = 'A-1'")->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		/**
		 * `alter (add foreign key (...))`/`alter (drop foreign key (...))`
		 * — ordinary ObjectQuel DDL, not raw SQL — register and remove a
		 * real composite constraint. Also the SQL Server-specific
		 * regression ForeignKeyActionNormalizer exists for: the DSL's
		 * `on update restrict` has no T-SQL equivalent and must read back
		 * as 'NO ACTION' once introspected.
		 */
		public function testAlterAddsAndDropsACompositeForeignKeyThroughTheDsl(): void {
			$this->adapter->execute('CREATE TABLE oq_fk_live_mssql_parents (tenant_id INT, number VARCHAR(20), PRIMARY KEY (tenant_id, number))');
			$this->adapter->execute('CREATE TABLE oq_fk_live_mssql_children (id INT IDENTITY PRIMARY KEY, order_tenant_id INT NOT NULL, order_number VARCHAR(20) NOT NULL)');

			$this->em->executeQuery(
				'alter oq_fk_live_mssql_children (add foreign key (order_tenant_id, order_number) ' .
				'references oq_fk_live_mssql_parents (tenant_id, number) on delete cascade on update restrict)'
			);

			$foreignKeys = $this->adapter->getForeignKeys('oq_fk_live_mssql_children');
			self::assertArrayHasKey('fk_oq_fk_live_mssql_children_order_tenant_id_order_number', $foreignKeys);

			$fk = $foreignKeys['fk_oq_fk_live_mssql_children_order_tenant_id_order_number'];
			self::assertSame(['order_tenant_id', 'order_number'], $fk->columns);
			self::assertSame(['tenant_id', 'number'], $fk->referencedColumns);
			self::assertSame('CASCADE', $fk->onDelete);
			// RESTRICT normalized to SQL Server's NO ACTION, not rejected as invalid syntax.
			self::assertSame('NO ACTION', $fk->onUpdate);

			$this->em->executeQuery('alter oq_fk_live_mssql_children (drop foreign key (order_tenant_id, order_number))');

			self::assertArrayNotHasKey(
				'fk_oq_fk_live_mssql_children_order_tenant_id_order_number',
				$this->adapter->getForeignKeys('oq_fk_live_mssql_children')
			);
		}

		/**
		 * The migration-generation round trip ForeignKeyMigrationTest only
		 * asserts as generated PHP source (SQLite-backed, string-only): here
		 * the exact `up()`/`down()` DSL text it emits for
		 * FkOrderClassLevelCompositeEntity's class-level composite
		 * @Orm\ForeignKey is additionally executed against a real SQL
		 * Server connection, proving the generated statements are valid
		 * T-SQL and round-trip the constraint, not just plausible-looking
		 * strings.
		 */
		public function testGeneratedCompositeForeignKeyMigrationAppliesAndRollsBackAgainstALiveConnection(): void {
			$this->adapter->execute('CREATE TABLE fk_composite_targets (tenant_id INT, number VARCHAR(20), PRIMARY KEY (tenant_id, number))');
			$this->adapter->execute('CREATE TABLE fk_orders_class_level_composite (id INT IDENTITY PRIMARY KEY, order_tenant_id INT NOT NULL, order_number VARCHAR(20) NOT NULL)');

			$entityStore = $this->em->getEntityStore();
			$platform = new PlatformCapabilities($this->adapter);
			$comparator = new ForeignKeyComparator($this->adapter, $entityStore, $platform);

			$fkDiff = $comparator->compareForeignKeys(FkOrderClassLevelCompositeEntity::class);
			self::assertArrayHasKey('fk_fk_orders_class_level_composite_order_tenant_id_order_number', $fkDiff['added']);

			$builder = new QuelMigrationBuilder($this->adapter, sys_get_temp_dir(), $platform);
			$method = new \ReflectionMethod(QuelMigrationBuilder::class, 'buildMigrationContent');
			$method->setAccessible(true);
			$changes = [
				'fk_orders_class_level_composite' => [
					'added'       => [],
					'modified'    => [],
					'deleted'     => [],
					'indexes'     => ['added' => [], 'modified' => [], 'deleted' => []],
					'foreignKeys' => $fkDiff,
				],
			];
			$content = $method->invoke($builder, 'TestSqlServerCompositeForeignKeyMigration', $changes);

			$upStatement = 'alter fk_orders_class_level_composite (add foreign key (order_tenant_id, order_number) ' .
				'references fk_composite_targets (tenant_id, number) on delete cascade on update restrict)';
			$downStatement = 'alter fk_orders_class_level_composite (drop foreign key (order_tenant_id, order_number))';

			self::assertStringContainsString($upStatement, $content);
			self::assertStringContainsString($downStatement, $content);

			// up(): the exact generated statement, executed for real.
			$this->em->executeQuery($upStatement);

			$foreignKeys = $this->adapter->getForeignKeys('fk_orders_class_level_composite');
			self::assertArrayHasKey('fk_fk_orders_class_level_composite_order_tenant_id_order_number', $foreignKeys);

			$fk = $foreignKeys['fk_fk_orders_class_level_composite_order_tenant_id_order_number'];
			self::assertSame(['order_tenant_id', 'order_number'], $fk->columns);
			self::assertSame('fk_composite_targets', $fk->referencedTable);
			self::assertSame(['tenant_id', 'number'], $fk->referencedColumns);
			self::assertSame('CASCADE', $fk->onDelete);
			self::assertSame('NO ACTION', $fk->onUpdate);

			// down(): the exact generated rollback statement, also executed for real.
			$this->em->executeQuery($downStatement);

			self::assertArrayNotHasKey(
				'fk_fk_orders_class_level_composite_order_tenant_id_order_number',
				$this->adapter->getForeignKeys('fk_orders_class_level_composite')
			);
		}
	}
