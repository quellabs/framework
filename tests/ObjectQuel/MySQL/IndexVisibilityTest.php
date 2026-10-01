<?php

	namespace Quellabs\ObjectQuel\Tests\MySQL;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `hide Name on Table` / `show Name on
	 * Table` statements, exercised end-to-end via
	 * EntityManager::executeQuery() against the suite's shared MySQL
	 * connection. Verifies the resulting optimizer visibility via
	 * information_schema.statistics.IS_VISIBLE, not just that no exception
	 * was thrown — same rationale as CreateIndexTest.
	 */
	class IndexVisibilityTest extends TestCase {

		private static int $tableCounter = 0;

		/** @var string[] Tables created by the current test, dropped in tearDown() */
		private array $createdTables = [];

		/**
		 * Index visibility is only available on MySQL and MariaDB.
		 * @return void
		 */
		protected function setUp(): void {
			if (in_array(self::em()->getConnection()->getDatabaseType(), ['pgsql', 'sqlite', 'sqlsrv'], true)) {
				self::markTestSkipped('Index visibility is not supported by this database.');
			}
		}

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
			return 'idx_vis_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		/**
		 * Creates a table with `id`, `email` columns and a plain index on
		 * `email` for the hide/show tests below to target.
		 */
		private function createTargetTableWithIndex(string $tableName, string $indexName): void {
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("
				create {$tableName} (
					id = integer identity,
					email = string(100),
					primary key (id)
				)
			");

			self::em()->executeQuery("index on {$tableName} is {$indexName} (email)");
		}

		private function isVisible(string $tableName, string $indexName): bool {
			$mariaDb = self::em()->getConnection()->getDatabaseType() === 'mariadb';
			$column = $mariaDb ? 'IGNORED' : 'IS_VISIBLE';
			$stmt = self::em()->getConnection()->execute(
				"SELECT {$column} FROM information_schema.statistics " .
				"WHERE table_schema = DATABASE() AND table_name = '{$tableName}' AND index_name = '{$indexName}' LIMIT 1"
			);

			self::assertNotNull($stmt);
			$row = $stmt->fetchAssoc();
			return $mariaDb ? $row['IGNORED'] === 'NO' : $row['IS_VISIBLE'] === 'YES';
		}

		public function testHideMarksTheIndexInvisibleToTheOptimizer(): void {
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_email_idx";
			$this->createTargetTableWithIndex($tableName, $indexName);

			self::assertTrue($this->isVisible($tableName, $indexName));

			$result = self::em()->executeQuery("hide {$indexName} on {$tableName}");

			self::assertNull($result);
			self::assertFalse($this->isVisible($tableName, $indexName));
		}

		public function testShowReversesAPreviouslyHiddenIndex(): void {
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_email_idx";
			$this->createTargetTableWithIndex($tableName, $indexName);

			self::em()->executeQuery("hide {$indexName} on {$tableName}");
			self::assertFalse($this->isVisible($tableName, $indexName));

			$result = self::em()->executeQuery("show {$indexName} on {$tableName}");

			self::assertNull($result);
			self::assertTrue($this->isVisible($tableName, $indexName));
		}

		public function testTargetIsALiteralTableNameNotAnEntityLookup(): void {
			// No range declaration, no EntityStore involved — same as
			// create/destroy index's table name.
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_email_idx";
			$this->createTargetTableWithIndex($tableName, $indexName);

			$result = self::em()->executeQuery("hide {$indexName} on {$tableName}");

			self::assertNull($result);
			self::assertFalse($this->isVisible($tableName, $indexName));
		}

		public function testRejectsRangeDeclarationBeforeHide(): void {
			$tableName = $this->nextTableName();
			$indexName = "{$tableName}_email_idx";
			$this->createTargetTableWithIndex($tableName, $indexName);

			// `hide` doesn't take ranges — one declared ahead of it is
			// rejected at parse time rather than silently discarded.
			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				range of x is UserEntity
				hide {$indexName} on {$tableName}
			");
		}
	}
