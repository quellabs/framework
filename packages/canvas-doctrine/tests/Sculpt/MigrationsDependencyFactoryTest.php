<?php

	namespace Quellabs\CanvasDoctrine\Tests\Sculpt;

	require_once __DIR__ . '/../Fixtures/DoctrineTestEntity.php';

	use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
	use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
	use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
	use Doctrine\Migrations\Tools\Console\Command\StatusCommand;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasDoctrine\Sculpt\MigrationsDependencyFactory;
	use Symfony\Component\Console\Input\ArrayInput;
	use Symfony\Component\Console\Output\NullOutput;

	/**
	 * Verifies MigrationsDependencyFactory's wiring and the diff/migrate/status
	 * lifecycle against a real SQLite file, using a fresh DependencyFactory per
	 * step to mirror the separate CLI process each Sculpt command actually runs in.
	 */
	class MigrationsDependencyFactoryTest extends TestCase {
		/** @var string */
		private string $migrationsPath;

		/** @var string */
		private string $databasePath;

		/**
		 * Creates a scratch migrations directory and SQLite file path for the test.
		 * @return void
		 */
		protected function setUp(): void {
			$this->migrationsPath = sys_get_temp_dir() . '/canvas-doctrine-migrations-test-' . uniqid();
			mkdir($this->migrationsPath);
			$this->databasePath = $this->migrationsPath . '/test.sqlite';
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
		 * Builds the canvas-doctrine config array used by every test here.
		 * @return array<string, mixed>
		 */
		private function config(): array {
			return [
				'entity_paths' => [__DIR__ . '/../Fixtures'],
				'migrations_path' => $this->migrationsPath,
				'migrations_namespace' => 'Quellabs\\CanvasDoctrine\\Tests\\Migrations',
				'development_mode' => true,
				'connection' => ['driver' => 'pdo_sqlite', 'dbname' => $this->databasePath],
			];
		}

		/**
		 * Verifies the migrations directory and table storage are wired from config.
		 * @return void
		 */
		public function testWiresMigrationsDirectoryAndTableStorage(): void {
			$dependencyFactory = MigrationsDependencyFactory::create($this->config());
			$configuration = $dependencyFactory->getConfiguration();

			$this->assertSame(
				['Quellabs\\CanvasDoctrine\\Tests\\Migrations' => $this->migrationsPath],
				$configuration->getMigrationDirectories()
			);

			$this->assertInstanceOf(TableMetadataStorageConfiguration::class, $configuration->getMetadataStorageConfiguration());
		}

		/**
		 * Rejects a missing migrations_path.
		 * @return void
		 */
		public function testRejectsMissingMigrationsPath(): void {
			$config = $this->config();
			unset($config['migrations_path']);
			$this->expectException(\InvalidArgumentException::class);
			MigrationsDependencyFactory::create($config);
		}

		/**
		 * Rejects a missing migrations_namespace.
		 * @return void
		 */
		public function testRejectsMissingMigrationsNamespace(): void {
			$config = $this->config();
			unset($config['migrations_namespace']);
			$this->expectException(\InvalidArgumentException::class);
			MigrationsDependencyFactory::create($config);
		}

		/**
		 * Runs diff, migrate, and status as separate DependencyFactory instances
		 * sharing the same SQLite file, the way separate CLI invocations would.
		 * @return void
		 */
		public function testDiffMigrateAndStatusLifecycle(): void {
			$config = $this->config();

			$diffInput = new ArrayInput(['--allow-empty-diff' => true]);
			$diffInput->setInteractive(false);
			$diffExitCode = (new DiffCommand(MigrationsDependencyFactory::create($config)))->run($diffInput, new NullOutput());
			$this->assertSame(0, $diffExitCode);

			$generated = glob($this->migrationsPath . '/Version*.php') ?: [];
			$this->assertCount(1, $generated);

			$migrateInput = new ArrayInput(['version' => 'latest']);
			$migrateInput->setInteractive(false);
			$migrateExitCode = (new MigrateCommand(MigrationsDependencyFactory::create($config)))->run($migrateInput, new NullOutput());
			$this->assertSame(0, $migrateExitCode);

			$statusDependencyFactory = MigrationsDependencyFactory::create($config);
			$statusInput = new ArrayInput([]);
			$statusInput->setInteractive(false);
			$statusExitCode = (new StatusCommand($statusDependencyFactory))->run($statusInput, new NullOutput());
			$this->assertSame(0, $statusExitCode);

			$executedMigrations = $statusDependencyFactory->getMetadataStorage()->getExecutedMigrations();
			$this->assertCount(1, $executedMigrations->getItems());

			$tables = $statusDependencyFactory->getConnection()->createSchemaManager()->listTableNames();
			$this->assertContains('DoctrineTestEntity', $tables);
			$this->assertContains('doctrine_migration_versions', $tables);
		}
	}
