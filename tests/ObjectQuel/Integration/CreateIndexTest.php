<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `index [unique] on Table is index_name
	 * (...)` statement, exercised end-to-end via
	 * EntityManager::executeQuery() against the suite's shared MySQL
	 * connection. Verifies the resulting index via
	 * DatabaseAdapter::getIndexes(), not just that no exception was thrown —
	 * the risk is in the generated DDL itself, same rationale as
	 * CreateTableTest.
	 */
	class CreateIndexTest extends TestCase {

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
			return 'idx_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		/**
		 * Creates a table with `id`, `email`, `tenant_id` columns for the
		 * index tests below to target.
		 */
		private function createTargetTable(string $tableName): void {
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("
				create {$tableName} (
					id = integer identity primary key,
					email = string(100) not null,
					tenant_id = integer not null
				)
			");
		}

		public function testCreatesAPlainIndex(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$result = self::em()->executeQuery("index on {$tableName} is {$tableName}_email_idx (email)");

			// A DDL statement has no rows to return.
			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey("{$tableName}_email_idx", $indexes);
			$this->assertSame(['email'], $indexes["{$tableName}_email_idx"]['columns']);
		}

		public function testCreatesAUniqueIndex(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$result = self::em()->executeQuery("index unique on {$tableName} is {$tableName}_email_uniq (email)");

			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey("{$tableName}_email_uniq", $indexes);
			$this->assertSame('unique', $indexes["{$tableName}_email_uniq"]['type']);
			$this->assertSame(['email'], $indexes["{$tableName}_email_uniq"]['columns']);
		}

		public function testCreatesAMultiColumnIndex(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$result = self::em()->executeQuery("index unique on {$tableName} is {$tableName}_composite_idx (tenant_id, email)");

			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey("{$tableName}_composite_idx", $indexes);
			$this->assertSame(['tenant_id', 'email'], $indexes["{$tableName}_composite_idx"]['columns']);
		}

		public function testUniqueIndexRejectsADuplicateValueOnInsert(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			self::em()->executeQuery("index unique on {$tableName} is {$tableName}_email_uniq (email)");

			$connection = self::em()->getConnection();
			$connection->execute("INSERT INTO `{$tableName}` (email, tenant_id) VALUES ('a@example.com', 1)");

			// execute() swallows the exception and returns null on failure
			// rather than throwing (see CreateTableExecutor's own note on
			// this) — a null return here proves the index actually enforces
			// uniqueness at the database level, not just that no exception
			// was thrown while creating it.
			$result = $connection->execute("INSERT INTO `{$tableName}` (email, tenant_id) VALUES ('a@example.com', 2)");
			$this->assertNull($result);
		}

		public function testIgnoresRangeDeclarationBeforeIndex(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			// Ranges are parsed once up front and shared across statements —
			// `index` just doesn't reference them, same as `create`.
			$result = self::em()->executeQuery("
				range of x is PostEntity
				index on {$tableName} is {$tableName}_email_idx (email)
			");

			$this->assertNull($result);
			$this->assertArrayHasKey("{$tableName}_email_idx", self::em()->getConnection()->getIndexes($tableName));
		}

		public function testRejectsIndexingATableThatDoesNotExist(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("index on {$tableName} is {$tableName}_email_idx (email)");
		}

		public function testRejectsIndexingAColumnThatDoesNotExist(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$this->expectException(QuelException::class);

			self::em()->executeQuery("index on {$tableName} is {$tableName}_bad_idx (nonexistent_column)");
		}

		public function testCannotWriteBothUniqueAndFulltextOnTheSameIndex(): void {
			// `unique` and `fulltext` occupy the same grammar slot right
			// after `index` (see Rules\CreateIndex) — writing both is a
			// syntax error (QueryExecutor wraps the LexerException as
			// QuelException), not a semantic rejection.
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$this->expectException(QuelException::class);

			self::em()->executeQuery("index unique fulltext on {$tableName} is {$tableName}_bad_idx (email)");
		}

		public function testCreatesAFulltextIndexUsableWithMatchAgainst(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("
				create {$tableName} (
					id = integer identity primary key,
					bio = string(500) not null
				)
			");

			$result = self::em()->executeQuery("index fulltext on {$tableName} is {$tableName}_bio_fulltext (bio)");
			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey("{$tableName}_bio_fulltext", $indexes);
			$this->assertSame('fulltext', $indexes["{$tableName}_bio_fulltext"]['type']);

			$connection = self::em()->getConnection();
			$connection->execute("INSERT INTO `{$tableName}` (bio) VALUES ('Loves hiking in the mountains')");
			$connection->execute("INSERT INTO `{$tableName}` (bio) VALUES ('Enjoys quiet afternoons reading')");

			// Boolean mode, not natural language mode: InnoDB's natural-language
			// search treats a word present in 50%+ of a table's rows as a
			// stopword and excludes it — a real behavior, but one that makes
			// natural language mode nondeterministic on a tiny 2-row test
			// table (exactly the "hiking" case here). Boolean mode has no
			// such threshold.
			$rows = $connection
				->execute("SELECT bio FROM `{$tableName}` WHERE MATCH(bio) AGAINST ('+hiking' IN BOOLEAN MODE)")
				->fetchAll('assoc');

			$this->assertCount(1, $rows);
			$this->assertSame('Loves hiking in the mountains', $rows[0]['bio']);
		}
	}
