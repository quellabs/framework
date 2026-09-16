<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Migration\MigrationLocator;
	use Quellabs\ObjectQuel\Migration\MigrationRepository;
	use Quellabs\ObjectQuel\Migration\MigrationRunner;

	/**
	 * Integration coverage for MigrationLocator/MigrationRunner, exercised
	 * end-to-end against the suite's shared MySQL connection and real
	 * migration files written to a per-test temp directory (see Phase 3 of
	 * objectquel-migrations-implementation-plan.md).
	 *
	 * Migration classes are declared in the global namespace (matching the
	 * Phinx-style convention AbstractMigration/MigrationLocator document),
	 * so every test uses a fresh, uniquely-suffixed class name — `require`
	 * is process-wide, and PHPUnit runs the whole suite in one process.
	 */
	class MigrationRunnerTest extends TestCase {

		private string $migrationsDir;

		/** @var string[] Tables created by migrations under test, dropped in tearDown() */
		private array $createdTables = [];

		/** @var string[] Tracking tables created for this test, dropped in tearDown() */
		private array $trackingTables = [];

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function setUp(): void {
			$this->migrationsDir = sys_get_temp_dir() . '/oq_migrations_test_' . str_replace('.', '', uniqid('', true));
			mkdir($this->migrationsDir, 0777, true);
		}

		protected function tearDown(): void {
			$connection = self::em()->getConnection();

			foreach ([...$this->createdTables, ...$this->trackingTables] as $tableName) {
				$connection->execute("DROP TABLE IF EXISTS `{$tableName}`");
			}

			$this->createdTables = [];
			$this->trackingTables = [];

			foreach (glob($this->migrationsDir . '/*.php') ?: [] as $file) {
				unlink($file);
			}

			rmdir($this->migrationsDir);
		}

		private function uniqueSuffix(): string {
			return str_replace('.', '', uniqid('', true));
		}

		private function nextTableName(): string {
			$name = 'mru_test_' . getmypid() . '_' . $this->uniqueSuffix();
			$this->createdTables[] = $name;
			return $name;
		}

		/**
		 * Writes a `<version>_<ClassName>.php` migration file whose up()
		 * creates $tableName and whose down() destroys it — the smallest
		 * real DDL round trip that still proves the file was located,
		 * required, instantiated, and actually run.
		 */
		private function writeCreateTableMigration(int $version, string $tableName): string {
			$className = 'TestMigration_' . $this->uniqueSuffix();

			file_put_contents(
				"{$this->migrationsDir}/{$version}_{$className}.php",
				"<?php\n" .
				"class {$className} extends \\Quellabs\\ObjectQuel\\Migration\\AbstractMigration {\n" .
				"    public function up(): void {\n" .
				"        \$this->query('create {$tableName} (id = integer identity, primary key (id))');\n" .
				"    }\n" .
				"    public function down(): void {\n" .
				"        \$this->query('destroy {$tableName}');\n" .
				"    }\n" .
				"}\n"
			);

			return $className;
		}

		/**
		 * A migration whose up() runs invalid Quel, so it always throws —
		 * for pinning "a failing migration doesn't get recorded and stops
		 * later migrations from running".
		 */
		private function writeFailingMigration(int $version): string {
			$className = 'TestMigration_' . $this->uniqueSuffix();

			file_put_contents(
				"{$this->migrationsDir}/{$version}_{$className}.php",
				"<?php\n" .
				"class {$className} extends \\Quellabs\\ObjectQuel\\Migration\\AbstractMigration {\n" .
				"    public function up(): void {\n" .
				"        \$this->query('this is not valid quel');\n" .
				"    }\n" .
				"    public function down(): void {\n" .
				"    }\n" .
				"}\n"
			);

			return $className;
		}

		private function makeRunner(): MigrationRunner {
			$trackingTable = 'mru_track_' . getmypid() . '_' . $this->uniqueSuffix();
			$this->trackingTables[] = $trackingTable;

			$repository = new MigrationRepository(self::em(), $trackingTable);
			$locator = new MigrationLocator(self::em(), $this->migrationsDir);
			$platform = new PlatformCapabilities(self::em()->getConnection());

			return new MigrationRunner(self::em(), $platform, $repository, $locator);
		}

		public function testGetPendingReturnsEveryLocatedMigrationWhenNoneApplied(): void {
			$this->writeCreateTableMigration(20260101000001, $this->nextTableName());
			$this->writeCreateTableMigration(20260101000002, $this->nextTableName());

			$runner = $this->makeRunner();

			$this->assertCount(2, $runner->getPending());
		}

		public function testMigrateAppliesEveryPendingMigrationAndCreatesTheirTables(): void {
			$tableA = $this->nextTableName();
			$tableB = $this->nextTableName();
			$this->writeCreateTableMigration(20260101000001, $tableA);
			$this->writeCreateTableMigration(20260101000002, $tableB);

			$runner = $this->makeRunner();
			$runner->migrate();

			$tables = self::em()->getConnection()->getTables();
			$this->assertContains($tableA, $tables);
			$this->assertContains($tableB, $tables);
			$this->assertSame([], $runner->getPending());
		}

		public function testMigrateWithTargetStopsAfterTheGivenVersionInclusive(): void {
			$tableA = $this->nextTableName();
			$tableB = $this->nextTableName();
			$versionA = 20260101000001;
			$versionB = 20260101000002;
			$this->writeCreateTableMigration($versionA, $tableA);
			$this->writeCreateTableMigration($versionB, $tableB);

			$runner = $this->makeRunner();
			$runner->migrate($versionA);

			$tables = self::em()->getConnection()->getTables();
			$this->assertContains($tableA, $tables);
			$this->assertNotContains($tableB, $tables);

			$pending = $runner->getPending();
			$this->assertCount(1, $pending);
			$this->assertSame($versionB, $pending[0]->version);
		}

		public function testRollbackWithNoArgumentsRevertsOnlyTheMostRecentMigration(): void {
			$tableA = $this->nextTableName();
			$tableB = $this->nextTableName();
			$this->writeCreateTableMigration(20260101000001, $tableA);
			$this->writeCreateTableMigration(20260101000002, $tableB);

			$runner = $this->makeRunner();
			$runner->migrate();
			$runner->rollback();

			$tables = self::em()->getConnection()->getTables();
			$this->assertContains($tableA, $tables);
			$this->assertNotContains($tableB, $tables);
		}

		public function testRollbackWithStepsRevertsThatManyMostRecentMigrations(): void {
			$tableA = $this->nextTableName();
			$tableB = $this->nextTableName();
			$tableC = $this->nextTableName();
			$this->writeCreateTableMigration(20260101000001, $tableA);
			$this->writeCreateTableMigration(20260101000002, $tableB);
			$this->writeCreateTableMigration(20260101000003, $tableC);

			$runner = $this->makeRunner();
			$runner->migrate();
			$runner->rollback(null, 2);

			$tables = self::em()->getConnection()->getTables();
			$this->assertContains($tableA, $tables);
			$this->assertNotContains($tableB, $tables);
			$this->assertNotContains($tableC, $tables);
		}

		public function testRollbackWithTargetRevertsEverythingAfterIt(): void {
			$tableA = $this->nextTableName();
			$tableB = $this->nextTableName();
			$tableC = $this->nextTableName();
			$versionA = 20260101000001;
			$this->writeCreateTableMigration($versionA, $tableA);
			$this->writeCreateTableMigration(20260101000002, $tableB);
			$this->writeCreateTableMigration(20260101000003, $tableC);

			$runner = $this->makeRunner();
			$runner->migrate();
			$runner->rollback($versionA);

			$tables = self::em()->getConnection()->getTables();
			$this->assertContains($tableA, $tables);
			$this->assertNotContains($tableB, $tables);
			$this->assertNotContains($tableC, $tables);
		}

		public function testStatusReportsAppliedAndPendingMigrations(): void {
			$versionA = 20260101000001;
			$versionB = 20260101000002;
			$this->writeCreateTableMigration($versionA, $this->nextTableName());
			$this->writeCreateTableMigration($versionB, $this->nextTableName());

			$runner = $this->makeRunner();
			$runner->migrate($versionA);

			$status = $runner->status();

			$this->assertSame($versionA, $status[0]['version']);
			$this->assertNotNull($status[0]['applied_at']);

			$this->assertSame($versionB, $status[1]['version']);
			$this->assertNull($status[1]['applied_at']);
		}

		/**
		 * A failing migration's up() must not be recorded as applied, and
		 * must stop any later pending migration from running at all.
		 */
		public function testAFailingMigrationIsNotRecordedAndStopsLaterMigrations(): void {
			$tableB = $this->nextTableName();
			$this->writeFailingMigration(20260101000001);
			$this->writeCreateTableMigration(20260101000002, $tableB);

			$runner = $this->makeRunner();

			try {
				$runner->migrate();
				$this->fail('Expected the failing migration to throw');
			} catch (\Throwable) {
				// Expected.
			}

			$this->assertNotContains($tableB, self::em()->getConnection()->getTables());
			$this->assertCount(2, $runner->getPending());
		}
	}
