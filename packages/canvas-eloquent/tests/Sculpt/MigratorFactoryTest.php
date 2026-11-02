<?php

	namespace Quellabs\CanvasEloquent\Tests\Sculpt;

	use Illuminate\Database\Migrations\MigrationCreator;
	use Illuminate\Filesystem\Filesystem;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasEloquent\Sculpt\MigratorFactory;

	/**
	 * Verifies MigratorFactory's wiring and the make/migrate/status/rollback
	 * lifecycle against a real SQLite file, using a fresh Migrator per step
	 * to mirror the separate CLI process each Sculpt command actually runs in.
	 */
	class MigratorFactoryTest extends TestCase {
		/** @var string */
		private string $migrationsPath;

		/** @var string */
		private string $databasePath;

		/**
		 * Creates a scratch migrations directory and SQLite file path for the test.
		 * @return void
		 */
		protected function setUp(): void {
			$this->migrationsPath = sys_get_temp_dir() . '/canvas-eloquent-migrations-test-' . uniqid();
			mkdir($this->migrationsPath);
			$this->databasePath = $this->migrationsPath . '/test.sqlite';

			// SQLite's connector requires the file to already exist; it never creates one.
			touch($this->databasePath);
		}

		/**
		 * Removes the scratch migrations directory and database file.
		 * @return void
		 */
		protected function tearDown(): void {
			// Forces any lingering PDO connection to close first; Windows can't unlink an open file.
			gc_collect_cycles();

			foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
				@unlink($file);
			}

			@unlink($this->databasePath);
			@rmdir($this->migrationsPath);
		}

		/**
		 * Builds the canvas-eloquent config array used by every test here.
		 * @return array<string, mixed>
		 */
		private function config(): array {
			return [
				'driver'          => 'sqlite',
				'database'        => $this->databasePath,
				'migrations_path' => $this->migrationsPath,
			];
		}

		/**
		 * Verifies the migrations table is created automatically when missing.
		 * @return void
		 */
		public function testCreatesMigrationsTableAutomatically(): void {
			$migrator = MigratorFactory::create($this->config());
			$this->assertTrue($migrator->repositoryExists());
		}

		/**
		 * Honors a custom migrations table name.
		 * @return void
		 */
		public function testHonorsCustomMigrationsTableName(): void {
			$config = $this->config();
			$config['migrations_table'] = 'custom_migrations';

			$migrator = MigratorFactory::create($config);

			$this->assertTrue($migrator->getRepository()->getConnection()->getSchemaBuilder()->hasTable('custom_migrations'));
			$this->assertFalse($migrator->getRepository()->getConnection()->getSchemaBuilder()->hasTable('migrations'));
		}

		/**
		 * Rejects a missing migrations_path.
		 * @return void
		 */
		public function testRejectsMissingMigrationsPath(): void {
			$config = $this->config();
			$config['migrations_path'] = null;

			$this->expectException(\InvalidArgumentException::class);
			MigratorFactory::resolveMigrationsPath($config);
		}

		/**
		 * Rejects a non-string migrations_table.
		 * @return void
		 */
		public function testRejectsInvalidMigrationsTable(): void {
			$config = $this->config();
			$config['migrations_table'] = '';

			$this->expectException(\InvalidArgumentException::class);
			MigratorFactory::create($config);
		}

		/**
		 * Runs make, migrate, status, and rollback as separate Migrator instances
		 * sharing the same SQLite file, the way separate CLI invocations would.
		 * Also confirms the Schema facade used by the generated migration's
		 * up()/down() methods resolves correctly against each fresh instance.
		 * @return void
		 */
		public function testMakeMigrateStatusAndRollbackLifecycle(): void {
			$config = $this->config();

			$file = (new MigrationCreator(new Filesystem(), ''))->create('create_widgets_table', $this->migrationsPath, 'widgets', true);
			$this->assertFileExists($file);

			$migrateMigrator = MigratorFactory::create($config);
			$ran = $migrateMigrator->run([MigratorFactory::resolveMigrationsPath($config)]);
			$this->assertCount(1, $ran);

			$statusMigrator = MigratorFactory::create($config);
			$this->assertCount(1, $statusMigrator->getRepository()->getRan());
			$this->assertTrue($statusMigrator->getRepository()->getConnection()->getSchemaBuilder()->hasTable('widgets'));

			$rollbackMigrator = MigratorFactory::create($config);
			$rolledBack = $rollbackMigrator->rollback([MigratorFactory::resolveMigrationsPath($config)]);
			$this->assertCount(1, $rolledBack);
			$this->assertCount(0, $rollbackMigrator->getRepository()->getRan());
			$this->assertFalse($rollbackMigrator->getRepository()->getConnection()->getSchemaBuilder()->hasTable('widgets'));
		}
	}
