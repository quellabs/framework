<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `destroy Name on Table [if exists]`
	 * statement (the index form — see objectquel-destroy-index-plan.md),
	 * exercised end-to-end via EntityManager::executeQuery() against the
	 * suite's shared MySQL connection. See tests/Integration/DestroyTest
	 * for the table form.
	 *
	 * sqlsrv/sqlite fulltext-index destroy are out of scope for this pass
	 * (see the plan doc's "deferred" section) and have no coverage here.
	 */
	class DestroyIndexTest extends TestCase {

		private static int $tableCounter = 0;

		/** @var string[] Tables created by the current test, dropped in tearDown() */
		private array $createdTables = [];

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function tearDown(): void {
			$connection = self::em()->getConnection();

			foreach ($this->createdTables as $tableName) {
				$connection->execute("DROP TABLE IF EXISTS `{$tableName}`");
			}

			$this->createdTables = [];
		}

		private function nextTableName(): string {
			return 'destroy_idx_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		/**
		 * Creates a table with `id`, `email` columns and a plain index on
		 * `email` for the destroy tests below to target.
		 */
		private function createTargetTableWithIndex(string $tableName, string $indexName): void {
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("
				create {$tableName} (
					id = integer identity primary key,
					email = string(100) not null
				)
			");

			self::em()->executeQuery("index on {$tableName} is {$indexName} (email)");
		}

		public function testDestroysAPlainIndex(): void {
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_email_idx";
			$this->createTargetTableWithIndex($tableName, $indexName);

			$result = self::em()->executeQuery("destroy {$indexName} on {$tableName}");

			$this->assertNull($result);
			$this->assertArrayNotHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testDestroysAFulltextIndex(): void {
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_bio_fulltext";
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("
				create {$tableName} (
					id = integer identity primary key,
					bio = string(500) not null
				)
			");
			self::em()->executeQuery("index fulltext on {$tableName} is {$indexName} (bio)");

			$result = self::em()->executeQuery("destroy {$indexName} on {$tableName}");

			$this->assertNull($result);
			$this->assertArrayNotHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testIgnoresRangeDeclarationBeforeDestroyIndex(): void {
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_email_idx";
			$this->createTargetTableWithIndex($tableName, $indexName);

			$result = self::em()->executeQuery("
				range of x is PostEntity
				destroy {$indexName} on {$tableName}
			");

			$this->assertNull($result);
			$this->assertArrayNotHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testRejectsDestroyingAnIndexThatDoesNotExist(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer)");

			// No IF EXISTS — a name that doesn't resolve to anything must
			// fail loudly (real engine error), not silently no-op.
			$this->expectException(QuelException::class);

			self::em()->executeQuery("destroy nonexistent_idx on {$tableName}");
		}

		public function testIfExistsSilentlyIgnoresAMissingIndex(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;
			self::em()->executeQuery("create {$tableName} (id = integer)");

			// This suite's live connection is plain MySQL, exercising the
			// dynamic-SQL emulation path (see QuelToSQLDestroyIndex).
			$result = self::em()->executeQuery("destroy nonexistent_idx on {$tableName} if exists");

			$this->assertNull($result);
		}

		public function testIfExistsStillDropsAnIndexThatDoesExist(): void {
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_email_idx";
			$this->createTargetTableWithIndex($tableName, $indexName);

			$result = self::em()->executeQuery("destroy {$indexName} on {$tableName} if exists");

			$this->assertNull($result);
			$this->assertArrayNotHasKey($indexName, self::em()->getConnection()->getIndexes($tableName));
		}

		public function testRejectsACommaSeparatedNameList(): void {
			$tableName = $this->nextTableName();
			$indexA = "{$tableName}_a_idx";
			$indexB = "{$tableName}_b_idx";

			$this->expectException(QuelException::class);

			self::em()->executeQuery("destroy {$indexA}, {$indexB} on {$tableName}");
		}
	}
