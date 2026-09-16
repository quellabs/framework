<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Migration\MigrationLocator;
	use Quellabs\ObjectQuel\Sculpt\Commands\MakeBlankMigrationCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * MakeBlankMigrationCommand (`make:blank-migration <Name>`) — see Phase 4
	 * of objectquel-migrations-implementation-plan.md. Needs no live database
	 * connection at all (it only writes a file), so — unlike
	 * QuelMigrateCommand — this can be tested end-to-end without touching
	 * the "only one EntityManager per process" constraint the rest of this
	 * suite works around (see tests/bootstrap.php).
	 */
	class MakeBlankMigrationCommandTest extends TestCase {

		private string $migrationsDir;

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function setUp(): void {
			$this->migrationsDir = sys_get_temp_dir() . '/oq_mmc_test_' . str_replace('.', '', uniqid('', true));
			mkdir($this->migrationsDir, 0777, true);
		}

		protected function tearDown(): void {
			foreach (glob($this->migrationsDir . '/*.php') ?: [] as $file) {
				unlink($file);
			}

			rmdir($this->migrationsDir);
		}

		private function makeProvider(): ServiceProvider {
			$provider = new ServiceProvider();
			$provider->setConfig([
				'migrations_path'  => $this->migrationsDir,
				'entity_path'      => $this->migrationsDir,
				'entity_namespace' => 'App\\Entities',
			]);

			return $provider;
		}

		/**
		 * @param string[] $args
		 * @return array{0: int, 1: string}
		 */
		private function runCommand(array $args): array {
			$stream = fopen('php://memory', 'w+');
			$output = new ConsoleOutput($stream);
			$input = new ConsoleInput($output, fopen('php://memory', 'r+'));
			$command = new MakeBlankMigrationCommand($input, $output, $this->makeProvider());

			$exitCode = $command->execute(new ConfigurationManager($args));

			rewind($stream);
			return [$exitCode, stream_get_contents($stream)];
		}

		public function testCreatesABlankMigrationFile(): void {
			[$exitCode, $out] = $this->runCommand(['BackfillUserStatus']);

			$this->assertSame(0, $exitCode);
			$this->assertStringContainsString('Success!', $out);

			$files = glob($this->migrationsDir . '/*_BackfillUserStatus.php');
			$this->assertCount(1, $files);

			$content = file_get_contents($files[0]);
			$this->assertStringContainsString('class BackfillUserStatus extends AbstractMigration', $content);
			$this->assertStringContainsString('public function up(): void {', $content);
			$this->assertStringContainsString('public function down(): void {', $content);
		}

		public function testFilenameStartsWithA14DigitTimestamp(): void {
			$this->runCommand(['BackfillUserStatus']);

			$files = glob($this->migrationsDir . '/*.php');
			$this->assertCount(1, $files);
			$this->assertMatchesRegularExpression('/^\d{14}_BackfillUserStatus\.php$/', basename($files[0]));
		}

		/**
		 * The generated file must be a real, locatable migration — round
		 * trips it through MigrationLocator, using the suite's shared
		 * EntityManager rather than building a second one.
		 */
		public function testGeneratedFileIsALocatableValidMigration(): void {
			$this->runCommand(['BackfillUserStatus']);

			$locator = new MigrationLocator(self::em(), $this->migrationsDir);
			$located = $locator->locate();

			$this->assertCount(1, $located);
			$this->assertSame('BackfillUserStatus', $located[0]->name);
		}

		public function testRejectsAMissingNameArgument(): void {
			[$exitCode, $out] = $this->runCommand([]);

			$this->assertSame(1, $exitCode);
			$this->assertStringContainsString('Missing required argument', $out);
		}

		public function testRejectsAnInvalidPhpIdentifier(): void {
			[$exitCode, $out] = $this->runCommand(['123-not-valid']);

			$this->assertSame(1, $exitCode);
			$this->assertStringContainsString('Invalid migration name', $out);
		}

		public function testRejectsWritingOverAnExistingFileForTheSameNameAndTimestamp(): void {
			$version = date('YmdHis');
			file_put_contents("{$this->migrationsDir}/{$version}_BackfillUserStatus.php", "<?php\n");

			[$exitCode, $out] = $this->runCommand(['BackfillUserStatus']);

			if (date('YmdHis') !== $version) {
				$this->markTestSkipped('Ran across a second boundary; timestamps differed, no collision to test.');
			}

			$this->assertSame(1, $exitCode);
			$this->assertStringContainsString('already exists', $out);
		}
	}
