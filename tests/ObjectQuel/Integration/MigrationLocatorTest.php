<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Migration\MigrationLocator;

	/**
	 * Coverage for MigrationLocator's validation of the migrations
	 * directory's contents — the naming convention, and that a located
	 * file actually declares a usable AbstractMigration subclass (see
	 * Phase 3 of objectquel-migrations-implementation-plan.md).
	 */
	class MigrationLocatorTest extends TestCase {

		private string $migrationsDir;

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function setUp(): void {
			$this->migrationsDir = sys_get_temp_dir() . '/oq_migrations_locator_test_' . str_replace('.', '', uniqid('', true));
			mkdir($this->migrationsDir, 0777, true);
		}

		protected function tearDown(): void {
			foreach (glob($this->migrationsDir . '/*.php') ?: [] as $file) {
				unlink($file);
			}

			rmdir($this->migrationsDir);
		}

		private function uniqueSuffix(): string {
			return str_replace('.', '', uniqid('', true));
		}

		public function testReturnsAnEmptyListWhenTheDirectoryDoesNotExist(): void {
			$locator = new MigrationLocator(self::em(), $this->migrationsDir . '/does_not_exist');

			$this->assertSame([], $locator->locate());
		}

		public function testReturnsAnEmptyListWhenTheDirectoryIsEmpty(): void {
			$locator = new MigrationLocator(self::em(), $this->migrationsDir);

			$this->assertSame([], $locator->locate());
		}

		public function testRejectsAFileNotMatchingTheNamingConvention(): void {
			file_put_contents("{$this->migrationsDir}/not_a_migration.php", "<?php\n");

			$locator = new MigrationLocator(self::em(), $this->migrationsDir);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("naming convention");

			$locator->locate();
		}

		public function testRejectsAFileWhoseClassDoesNotExist(): void {
			$className = 'MissingClass_' . $this->uniqueSuffix();
			file_put_contents("{$this->migrationsDir}/20260101000001_{$className}.php", "<?php\n// no class declared here\n");

			$locator = new MigrationLocator(self::em(), $this->migrationsDir);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("does not declare the expected class '{$className}'");

			$locator->locate();
		}

		public function testRejectsAClassThatDoesNotExtendAbstractMigration(): void {
			$className = 'NotAMigration_' . $this->uniqueSuffix();
			file_put_contents(
				"{$this->migrationsDir}/20260101000001_{$className}.php",
				"<?php\nclass {$className} {}\n"
			);

			$locator = new MigrationLocator(self::em(), $this->migrationsDir);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("must extend");

			$locator->locate();
		}

		public function testRejectsTwoFilesDeclaringTheSameVersion(): void {
			$classNameA = 'DuplicateA_' . $this->uniqueSuffix();
			$classNameB = 'DuplicateB_' . $this->uniqueSuffix();

			$migrationBody = static fn(string $className) =>
				"<?php\nclass {$className} extends \\Quellabs\\ObjectQuel\\Migration\\AbstractMigration {\n" .
				"    public function up(): void {}\n" .
				"    public function down(): void {}\n" .
				"}\n";

			file_put_contents("{$this->migrationsDir}/20260101000001_{$classNameA}.php", $migrationBody($classNameA));
			file_put_contents("{$this->migrationsDir}/20260101000001_{$classNameB}.php", $migrationBody($classNameB));

			$locator = new MigrationLocator(self::em(), $this->migrationsDir);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('Duplicate migration version');

			$locator->locate();
		}

		public function testLocatesAValidMigrationAndInstantiatesIt(): void {
			$className = 'ValidMigration_' . $this->uniqueSuffix();
			file_put_contents(
				"{$this->migrationsDir}/20260101000001_{$className}.php",
				"<?php\nclass {$className} extends \\Quellabs\\ObjectQuel\\Migration\\AbstractMigration {\n" .
				"    public function up(): void {}\n" .
				"    public function down(): void {}\n" .
				"}\n"
			);

			$locator = new MigrationLocator(self::em(), $this->migrationsDir);
			$located = $locator->locate();

			$this->assertCount(1, $located);
			$this->assertSame(20260101000001, $located[0]->version);
			$this->assertSame($className, $located[0]->name);
			$this->assertInstanceOf($className, $located[0]->migration);
		}
	}
