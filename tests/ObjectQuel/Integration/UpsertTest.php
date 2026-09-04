<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's upsert extension — `append to u (...)
	 * or replace (...) where <cond>` — exercised end-to-end via
	 * EntityManager::executeStatement() against the suite's shared MySQL
	 * connection (see objectquel-upsert-plan.md).
	 *
	 * MySQL's `INSERT ... ON DUPLICATE KEY UPDATE` is the only dialect this
	 * suite can run live; the pgsql/sqlite/sqlsrv branches are covered by
	 * generated-SQL string assertions in
	 * tests/Unit/QuelToSQLAppendUpsertTest.php instead (same split
	 * CreateTableTest/QuelToSQLCreateTest use for `create`).
	 *
	 * Uses App\Entities\UpsertConflictEntity, a dedicated fixture entity
	 * with a real @Orm\UniqueIndex — UserEntity/PostEntity have none
	 * (UserEntity's idx_username is a plain, non-unique index), and an
	 * upsert's conflict target must be backed by a real constraint. `create`
	 * has no QUEL syntax for declaring a unique index (v1 scope), so the
	 * table is managed directly with raw SQL here.
	 */
	class UpsertTest extends TestCase {

		private const string TABLE = 'upsert_conflict_test';

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function setUp(): void {
			$connection = self::em()->getConnection();
			$connection->execute("DROP TABLE IF EXISTS `" . self::TABLE . "`");
			$connection->execute("
				CREATE TABLE `" . self::TABLE . "` (
					`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					`email` VARCHAR(100) NOT NULL,
					`name` VARCHAR(255) NOT NULL,
					UNIQUE KEY `uniq_email` (`email`)
				)
			");
		}

		protected function tearDown(): void {
			self::em()->getConnection()->execute("DROP TABLE IF EXISTS `" . self::TABLE . "`");
		}

		private const string UPSERT_QUERY = <<<'QUEL'
			range of u is App\Entities\UpsertConflictEntity
			append to u (email = :e, name = :n) or replace (name = :n) where u.email = :e
			QUEL;

		public function testInsertsWhenNoConflictingRowExists(): void {
			$result = self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'alice@example.com', 'n' => 'Alice']);

			$this->assertSame(1, $result->getAffectedRows());
			$this->assertIsInt($result->getGeneratedId());

			$rows = self::em()->getAll('range of u is App\Entities\UpsertConflictEntity retrieve (u.email, u.name)');
			$this->assertCount(1, $rows);
			$this->assertSame('Alice', $rows[0]['u.name']);
		}

		public function testUpdatesTheMatchingRowOnConflictInsteadOfInsertingADuplicate(): void {
			self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'alice@example.com', 'n' => 'Alice']);

			// MySQL's ON DUPLICATE KEY UPDATE reports 2 (not 1) for a row
			// that was updated via the duplicate-key path — a well-known,
			// documented MySQL quirk, not a bug in the generated SQL.
			$result = self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'alice@example.com', 'n' => 'Alice V2']);
			$this->assertSame(2, $result->getAffectedRows());

			$rows = self::em()->getAll('range of u is App\Entities\UpsertConflictEntity retrieve (u.email, u.name)');
			$this->assertCount(1, $rows);
			$this->assertSame('Alice V2', $rows[0]['u.name']);
		}

		public function testHandlesMultipleDistinctRowsWithoutConflict(): void {
			self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'alice@example.com', 'n' => 'Alice']);
			self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'bob@example.com', 'n' => 'Bob']);

			$names = self::em()->getCol('range of u is App\Entities\UpsertConflictEntity retrieve (u.name) sort by u.name asc');
			$this->assertSame(['Alice', 'Bob'], $names);
		}

		public function testMultiRowAppendUpsertsEachRowIndependently(): void {
			self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'alice@example.com', 'n' => 'Alice']);

			$result = self::em()->executeStatement('
				range of u is App\Entities\UpsertConflictEntity
				append to u
					(email = :e1, name = :n1),
					(email = :e2, name = :n2)
				or replace (name = :n1) where u.email = :e1
			', ['e1' => 'alice@example.com', 'n1' => 'Alice V2', 'e2' => 'carol@example.com', 'n2' => 'Carol']);

			$this->assertGreaterThanOrEqual(1, $result->getAffectedRows());

			$rows = self::em()->getAll('range of u is App\Entities\UpsertConflictEntity retrieve (u.email, u.name) sort by u.email asc');
			$this->assertCount(2, $rows);
			$this->assertSame('Alice V2', $rows[0]['u.name']);
			$this->assertSame('Carol', $rows[1]['u.name']);
		}

		public function testNoExplicitListOverwritesWithTheInsertedValuesOnConflict(): void {
			$noListQuery = '
				range of u is App\Entities\UpsertConflictEntity
				append to u (email = :e, name = :n) or replace where u.email = :e
			';

			self::em()->executeStatement($noListQuery, ['e' => 'alice@example.com', 'n' => 'Alice']);
			self::em()->executeStatement($noListQuery, ['e' => 'alice@example.com', 'n' => 'Alice V2']);

			$rows = self::em()->getAll('range of u is App\Entities\UpsertConflictEntity retrieve (u.name) where u.email = "alice@example.com"');
			$this->assertSame('Alice V2', $rows[0]['u.name']);
		}

		public function testNoExplicitListUpdatesEachConflictingRowWithItsOwnValuesInAMultiRowAppend(): void {
			// Seed two rows first, so the multi-row upsert below conflicts on both.
			self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'alice@example.com', 'n' => 'Alice']);
			self::em()->executeStatement(self::UPSERT_QUERY, ['e' => 'bob@example.com', 'n' => 'Bob']);

			// Guards against a real correctness bug: if the default "no explicit
			// list" SET clause were built from one row's literal compiled values
			// instead of each dialect's "row that would have been inserted"
			// reference (EXCLUDED/VALUES()/source.col), every conflicting row
			// would be overwritten with the *first* row's values instead of its
			// own.
			self::em()->executeStatement('
				range of u is App\Entities\UpsertConflictEntity
				append to u
					(email = :e1, name = :n1),
					(email = :e2, name = :n2)
				or replace where u.email = :e1
			', ['e1' => 'alice@example.com', 'n1' => 'Alice V2', 'e2' => 'bob@example.com', 'n2' => 'Bob V2']);

			$rows = self::em()->getAll('range of u is App\Entities\UpsertConflictEntity retrieve (u.email, u.name) sort by u.email asc');
			$this->assertCount(2, $rows);
			$this->assertSame('Alice V2', $rows[0]['u.name']);
			$this->assertSame('Bob V2', $rows[1]['u.name']);
		}

		public function testRejectsAConflictTargetNotBackedByAUniqueConstraint(): void {
			$this->expectException(QuelException::class);

			self::em()->executeStatement('
				range of u is App\Entities\UpsertConflictEntity
				append to u (email = :e, name = :n) or replace (name = :n) where u.name = :n
			', ['e' => 'x@example.com', 'n' => 'X']);
		}

		public function testRejectsATargetThatIsNotADeclaredRange(): void {
			$this->expectException(QuelException::class);

			self::em()->executeStatement(
				'append to App\Entities\UpsertConflictEntity (email = :e, name = :n) or replace (name = :n) where email = :e',
				['e' => 'x@example.com', 'n' => 'X']
			);
		}
	}
