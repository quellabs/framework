<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `create [temporary] Name (...)` statement,
	 * exercised end-to-end through EntityManager::executeQuery() against a live
	 * MySQL connection (the suite's shared connection — see bootstrap.php).
	 *
	 * Verifies the table this statement produces via
	 * DatabaseAdapter::getColumns()/getPrimaryKey(), not just that no exception
	 * was thrown — the risk is in the generated DDL fragments themselves
	 * (constraint order, AUTO_INCREMENT placement), same rationale as
	 * TempTableExecutorTest.
	 */
	class CreateTableTest extends TestCase {

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

		/**
		 * A unique table name per call, so tests never collide on a leftover
		 * table from a previous run in the same session.
		 */
		private function nextTableName(): string {
			return 'ct_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		public function testCreatesPermanentTableWithConstraints(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			$result = self::em()->executeQuery("
				create {$tableName} (
					id = integer identity primary key,
					message = string(500) not null,
					amount = decimal(10,2),
					created_at = datetime not null
				)
			");

			// A DDL statement has no rows to return.
			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);

			$this->assertSame('integer', $columns['id']['type']);
			$this->assertTrue($columns['id']['identity']);
			$this->assertTrue($columns['id']['primary_key']);
			$this->assertFalse($columns['id']['nullable']);

			$this->assertSame('string', $columns['message']['type']);
			$this->assertSame(500, $columns['message']['limit']);
			$this->assertFalse($columns['message']['nullable']);

			$this->assertSame('decimal', $columns['amount']['type']);
			$this->assertSame(10, $columns['amount']['precision']);
			$this->assertSame(2, $columns['amount']['scale']);
			$this->assertTrue($columns['amount']['nullable']);

			$this->assertSame('datetime', $columns['created_at']['type']);
			$this->assertFalse($columns['created_at']['nullable']);

			$this->assertSame('id', self::em()->getConnection()->getPrimaryKey($tableName));
		}

		public function testCreatesTemporaryTableUsableInTheSameSession(): void {
			$tableName = $this->nextTableName();

			$result = self::em()->executeQuery("
				create temporary {$tableName} (
					user_id = integer,
					total = decimal
				)
			");

			$this->assertNull($result);

			// Session-scoped temp tables drop themselves when the connection
			// closes; explicitly drop it now so a leaked session doesn't leave
			// it behind for the rest of the suite.
			$connection = self::em()->getConnection();

			try {
				$connection->execute("INSERT INTO `{$tableName}` (user_id, total) VALUES (1, 9.5)");
				$stmt = $connection->execute("SELECT * FROM `{$tableName}`");
				$rows = $stmt->fetchAll('assoc');

				$this->assertCount(1, $rows);
				$this->assertEquals(1, $rows[0]['user_id']);
			} finally {
				$connection->execute("DROP TEMPORARY TABLE IF EXISTS `{$tableName}`");
			}
		}

		public function testRejectsMoreThanOnePrimaryKeyColumn(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = integer primary key,
					b = integer primary key
				)
			");
		}

		public function testRejectsIdentityWithoutPrimaryKey(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = integer identity
				)
			");
		}

		public function testIgnoresRangeDeclarationBeforeCreate(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			// Parser::parse() parses 'range of ...' declarations once up front,
			// shared across every statement in the do-while loop — a user could
			// declare ranges and then run several `retrieve` statements against
			// them. `create` simply doesn't reference ranges, so one declared
			// ahead of it is unused, not an error.
			$result = self::em()->executeQuery("
				range of x is PostEntity
				create {$tableName} (id = integer)
			");

			$this->assertNull($result);
			$this->assertArrayHasKey('id', self::em()->getConnection()->getColumns($tableName));
		}

		public function testRejectsCreatingATableThatAlreadyExists(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("create {$tableName} (id = integer)");

			// DatabaseAdapter::execute() swallows the underlying DB exception and
			// returns null on failure rather than throwing — CreateTableExecutor
			// must detect that null return itself rather than relying on a
			// try/catch that would never fire. A duplicate CREATE TABLE is a real
			// DB-level failure (unlike the parse-time rejections above), so this
			// is the one test that actually exercises that path.
			$this->expectException(QuelException::class);

			self::em()->executeQuery("create {$tableName} (id = integer)");
		}

		public function testRejectsUnknownColumnType(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = notareltype
				)
			");
		}
	}
