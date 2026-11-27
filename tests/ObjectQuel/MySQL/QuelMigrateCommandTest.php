<?php

	namespace Quellabs\ObjectQuel\Tests\MySQL;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Sculpt\Commands\MakeBlankMigrationCommand;
	use Quellabs\ObjectQuel\Sculpt\Commands\QuelMigrateCommand;
	use Quellabs\ObjectQuel\Sculpt\Commands\StatusCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\ObjectQuel\Tests\Support\CommandRunResult;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * End-to-end tests for quel:migrate and quel:migrate:status. The commands build their own
	 * EntityManager through ServiceProvider, alongside the suite's shared one.
	 */
	class QuelMigrateCommandTest extends TestCase {

		private string $migrationsDir;
		private string $entityPath;
		private string $trackingTable;
		private ServiceProvider $provider;

		/** @var list<string> Tables created by migrations in the current test */
		private array $createdTables = [];

		/**
		 * Creates temp directories, a unique tracking table name and the service provider.
		 * @return void
		 */
		protected function setUp(): void {
			$this->migrationsDir = sys_get_temp_dir() . '/oq_qmc_test_' . self::uniqueSuffix();
			mkdir($this->migrationsDir, 0777, true);

			// Dedicated empty directory, so entity discovery doesn't scan the whole temp dir
			$this->entityPath = sys_get_temp_dir() . '/oq_qmc_test_entities_' . self::uniqueSuffix();
			mkdir($this->entityPath, 0777, true);

			$this->trackingTable = 'qmc_test_track_' . self::uniqueSuffix();
			$this->createdTables = [];

			$this->provider = new ServiceProvider();
			$this->provider->setConfig([
				'driver'           => 'mysql',
				'host'             => getenv('TEST_DB_HOST') ?: '127.0.0.1',
				'port'             => (int)(getenv('TEST_DB_PORT') ?: 3306),
				'database'         => getenv('TEST_DB_NAME') ?: 'canvas_blog',
				'username'         => getenv('TEST_DB_USER') ?: 'root',
				'password'         => getenv('TEST_DB_PASS') ?: '',
				'encoding'         => 'utf8mb4',
				'entity_namespace' => 'App\\Entities',
				'entity_path'      => $this->entityPath,
				'migrations_path'  => $this->migrationsDir,
				'migrations_table' => $this->trackingTable
			]);
		}

		/**
		 * Drops created tables and removes temp directories.
		 * @return void
		 */
		protected function tearDown(): void {
			$adapter = $this->provider->getDatabaseAdapter();

			foreach ([...$this->createdTables, $this->trackingTable] as $tableName) {
				$adapter->execute("DROP TABLE IF EXISTS `{$tableName}`");
			}

			foreach (glob($this->migrationsDir . '/*.php') ?: [] as $file) {
				unlink($file);
			}

			rmdir($this->migrationsDir);
			rmdir($this->entityPath);
		}

		/**
		 * Returns a unique, identifier-safe suffix.
		 * @return string
		 */
		private static function uniqueSuffix(): string {
			return str_replace('.', '', uniqid('', true));
		}

		/**
		 * Reserves a unique table name and records it for teardown.
		 * @return string
		 */
		private function nextTableName(): string {
			$name = 'qmc_test_' . self::uniqueSuffix();
			$this->createdTables[] = $name;
			return $name;
		}

		/**
		 * Writes a migration that creates and destroys the given table.
		 * @param int $version Migration version
		 * @param string $tableName Table the migration manages
		 * @return string Generated migration class name
		 */
		private function writeMigration(int $version, string $tableName): string {
			$className = 'QmcTestMigration_' . self::uniqueSuffix();

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
		 * Runs a Sculpt command and captures its output.
		 * @param class-string<CommandBase> $commandClass Command to run
		 * @param string[] $args Command arguments
		 * @return CommandRunResult
		 */
		private function runCommand(string $commandClass, array $args): CommandRunResult {
			$stream = fopen('php://memory', 'w+');
			$output = new ConsoleOutput($stream);
			$input = new ConsoleInput($output, fopen('php://memory', 'r+'));
			$command = new $commandClass($input, $output, $this->provider);

			$exitCode = $command->execute(new ConfigurationManager($args));

			rewind($stream);
			return new CommandRunResult($exitCode, stream_get_contents($stream));
		}

		/**
		 * Runs quel:migrate with the given arguments.
		 * @param string[] $args Command arguments
		 * @return CommandRunResult
		 */
		private function migrate(array $args): CommandRunResult {
			return $this->runCommand(QuelMigrateCommand::class, $args);
		}

		/**
		 * Asserts whether a table exists in the database.
		 * @param bool $expected Whether the table should exist
		 * @param string $tableName Table to check
		 * @return void
		 */
		private function assertTableExists(bool $expected, string $tableName): void {
			$tables = $this->provider->getDatabaseAdapter()->getTables();
			$this->assertSame($expected, in_array($tableName, $tables, true), "Table {$tableName} existence");
		}

		/**
		 * Reports when there is nothing to migrate.
		 * @return void
		 */
		public function testNoPendingMigrations(): void {
			$result = $this->migrate(['--force']);

			$this->assertSame(0, $result->getExitCode());
			$this->assertStringContainsString('No pending migrations found.', $result->getOutput());
		}

		/**
		 * Applies pending migrations with --force.
		 * @return void
		 */
		public function testForceAppliesPendingMigrations(): void {
			$table = $this->nextTableName();
			$this->writeMigration(20260101000001, $table);

			$result = $this->migrate(['--force']);

			$this->assertSame(0, $result->getExitCode());
			$this->assertStringContainsString('Migration completed successfully', $result->getOutput());
			$this->assertTableExists(true, $table);
		}

		/**
		 * --status and quel:migrate:status list applied and pending migrations without applying.
		 * @return void
		 */
		public function testStatusShowsAppliedAndPendingMigrations(): void {
			$this->writeMigration(20260101000001, $this->nextTableName());
			$this->migrate(['--force']);

			$pendingTable = $this->nextTableName();
			$pendingClass = $this->writeMigration(20260101000002, $pendingTable);

			foreach ([QuelMigrateCommand::class => ['--status'], StatusCommand::class => []] as $commandClass => $args) {
				$result = $this->runCommand($commandClass, $args);

				$this->assertSame(0, $result->getExitCode(), $commandClass);
				$this->assertStringContainsString('20260101000001', $result->getOutput(), $commandClass);
				$this->assertStringContainsString($pendingClass, $result->getOutput(), $commandClass);
			}

			$this->assertTableExists(false, $pendingTable);
		}

		/**
		 * --dry-run previews without applying.
		 * @return void
		 */
		public function testDryRunDoesNotApply(): void {
			$table = $this->nextTableName();
			$this->writeMigration(20260101000001, $table);

			$result = $this->migrate(['--dry-run']);

			$this->assertSame(0, $result->getExitCode());
			$this->assertStringContainsString('Dry run mode', $result->getOutput());
			$this->assertTableExists(false, $table);
		}

		/**
		 * --target stops at the given version.
		 * @return void
		 */
		public function testTargetStopsAtVersion(): void {
			$targetTable = $this->nextTableName();
			$laterTable = $this->nextTableName();
			$this->writeMigration(20260101000001, $targetTable);
			$this->writeMigration(20260101000002, $laterTable);

			$result = $this->migrate(['--force', '--target=20260101000001']);

			$this->assertSame(0, $result->getExitCode());
			$this->assertTableExists(true, $targetTable);
			$this->assertTableExists(false, $laterTable);
		}

		/**
		 * --rollback --dry-run previews; --rollback --force reverts the latest migration.
		 * @return void
		 */
		public function testRollback(): void {
			$table = $this->nextTableName();
			$this->writeMigration(20260101000001, $table);
			$this->migrate(['--force']);

			$dryRun = $this->migrate(['--rollback', '--dry-run']);
			$this->assertSame(0, $dryRun->getExitCode());
			$this->assertStringContainsString('Dry run mode', $dryRun->getOutput());
			$this->assertTableExists(true, $table);

			$rollback = $this->migrate(['--rollback', '--force']);
			$this->assertSame(0, $rollback->getExitCode());
			$this->assertStringContainsString('Rollback completed successfully', $rollback->getOutput());
			$this->assertTableExists(false, $table);
		}

		/**
		 * Full chain: make:blank-migration, hand-edit, quel:migrate, then --rollback.
		 * @return void
		 */
		public function testBlankMigrationChain(): void {
			$make = $this->runCommand(MakeBlankMigrationCommand::class, ['ChainTestMigration']);
			$this->assertSame(0, $make->getExitCode());
			$this->assertStringContainsString('Success!', $make->getOutput());

			$generatedFiles = glob($this->migrationsDir . '/*_ChainTestMigration.php') ?: [];
			$this->assertCount(1, $generatedFiles);

			// Fill in the empty up()/down() bodies
			$table = $this->nextTableName();
			$original = file_get_contents($generatedFiles[0]);

			$edited = str_replace(
				"public function up(): void {\n        }",
				"public function up(): void {\n            \$this->query('create {$table} (id = integer identity, primary key (id))');\n        }",
				$original
			);

			$edited = str_replace(
				"public function down(): void {\n        }",
				"public function down(): void {\n            \$this->query('destroy {$table}');\n        }",
				$edited
			);

			$this->assertNotSame($original, $edited, 'Hand-edit changed the generated file');
			file_put_contents($generatedFiles[0], $edited);

			$migrate = $this->migrate(['--force']);
			$this->assertSame(0, $migrate->getExitCode());
			$this->assertStringContainsString('Migration completed successfully', $migrate->getOutput());
			$this->assertTableExists(true, $table);

			$rollback = $this->migrate(['--rollback', '--force']);
			$this->assertSame(0, $rollback->getExitCode());
			$this->assertStringContainsString('Rollback completed successfully', $rollback->getOutput());
			$this->assertTableExists(false, $table);
		}
	}
