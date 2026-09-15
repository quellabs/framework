<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\CompiledAppendSql;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLAppend;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLUpsert;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Dialect-level coverage for upsert's on-conflict compile branches (see
	 * objectquel-upsert-plan.md) — mirrors QuelToSQLCreateTest's pattern:
	 * the suite's only live connection is MySQL (exercised end-to-end in
	 * tests/Integration/UpsertTest.php), so this is where pgsql/sqlite/sqlsrv
	 * generated SQL is actually compared. Needs a real EntityStore (unlike
	 * QuelToSQLCreateTest) since an upsert's conflict target is checked
	 * against App\Entities\UpsertConflictEntity's declared @Orm\UniqueIndex —
	 * a WHERE clause backed by it compiles to the dialect-native atomic form;
	 * one that isn't compiles to the two-statement fallback instead (see
	 * QuelToSQLUpsert::compileNonAtomicFallback()) — not rejected.
	 */
	class QuelToSQLAppendUpsertTest extends TestCase {

		private const string QUERY = <<<'QUEL'
			range of u is App\Entities\UpsertConflictEntity
			append to u (email = :e, name = :n) or replace (name = :n) where u.email = :e
			QUEL;

		private const string DEFAULT_LIST_QUERY = <<<'QUEL'
			range of u is App\Entities\UpsertConflictEntity
			append to u (email = :e, name = :n) or replace where u.email = :e
			QUEL;

		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		private function parse(string $query = self::QUERY): AstAppend {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstAppend::class, $ast);
			return $ast;
		}

		private function compileFull(AstAppend $ast, string $dialect, array $parameters = ['e' => 'a@example.com', 'n' => 'Alice']): CompiledAppendSql {
			$em = $this->em();
			$platform = new FakePlatformCapabilities($dialect);
			$versionValueHandler = $em->getUnitOfWork()->getVersionValueHandler();
			$replaceCompiler = new QuelToSQLReplace($em->getEntityStore(), $platform, $versionValueHandler);
			$upsertCompiler = new QuelToSQLUpsert($em->getEntityStore(), $platform, $replaceCompiler);
			$compiler = new QuelToSQLAppend($em, $platform, $upsertCompiler, $versionValueHandler);
			return $compiler->convertToSQL($ast, $parameters);
		}

		private function compile(AstAppend $ast, string $dialect, array $parameters = ['e' => 'a@example.com', 'n' => 'Alice']): string {
			return $this->compileFull($ast, $dialect, $parameters)->primarySql;
		}

		public function testPostgresCompilesToOnConflictDoUpdate(): void {
			self::assertSame(
				'INSERT INTO "upsert_conflict_test" ("email", "name") VALUES (:e, :n) ON CONFLICT ("email") DO UPDATE SET "name" = :n',
				$this->compile($this->parse(), 'pgsql')
			);
		}

		public function testSqliteCompilesToOnConflictDoUpdate(): void {
			self::assertSame(
				'INSERT INTO `upsert_conflict_test` (`email`, `name`) VALUES (:e, :n) ON CONFLICT (`email`) DO UPDATE SET `name` = :n',
				$this->compile($this->parse(), 'sqlite')
			);
		}

		public function testMysqlCompilesToOnDuplicateKeyUpdate(): void {
			// The documented dialect gap: MySQL's ON DUPLICATE KEY UPDATE has
			// no column-scoping — it fires on *any* unique-key collision on
			// the table, not only the WHERE-named conflict column(s). Nothing
			// in the generated SQL can express that scoping; this test exists
			// to keep the caveat visible, not to demonstrate a fix for it.
			self::assertSame(
				'INSERT INTO `upsert_conflict_test` (`email`, `name`) VALUES (:e, :n) ON DUPLICATE KEY UPDATE `name` = :n',
				$this->compile($this->parse(), 'mysql')
			);
		}

		public function testMariadbCompilesToOnDuplicateKeyUpdate(): void {
			self::assertSame(
				'INSERT INTO `upsert_conflict_test` (`email`, `name`) VALUES (:e, :n) ON DUPLICATE KEY UPDATE `name` = :n',
				$this->compile($this->parse(), 'mariadb')
			);
		}

		public function testSqlServerCompilesToMerge(): void {
			self::assertSame(
				'MERGE INTO [upsert_conflict_test] AS [__upsert_target] USING (VALUES (:e, :n)) AS [__upsert_source] ([email], [name]) ' .
				'ON [__upsert_target].[email] = [__upsert_source].[email] ' .
				'WHEN MATCHED THEN UPDATE SET [name] = :n ' .
				'WHEN NOT MATCHED THEN INSERT ([email], [name]) VALUES ([__upsert_source].[email], [__upsert_source].[name]);',
				$this->compile($this->parse(), 'sqlsrv')
			);
		}

		public function testSqlServerMergeGeneralizesToMultipleRows(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u
					(email = :e1, name = :n1),
					(email = :e2, name = :n2)
				or replace (name = :n1) where u.email = :e1
			'), $this->em()->getEntityStore()))->parse();

			self::assertSame(
				'MERGE INTO [upsert_conflict_test] AS [__upsert_target] USING (VALUES (:e1, :n1), (:e2, :n2)) AS [__upsert_source] ([email], [name]) ' .
				'ON [__upsert_target].[email] = [__upsert_source].[email] ' .
				'WHEN MATCHED THEN UPDATE SET [name] = :n1 ' .
				'WHEN NOT MATCHED THEN INSERT ([email], [name]) VALUES ([__upsert_source].[email], [__upsert_source].[name]);',
				$this->compile($ast, 'sqlsrv', ['e1' => 'a', 'n1' => 'A', 'e2' => 'b', 'n2' => 'B'])
			);
		}

		public function testPostgresWithNoExplicitListDefaultsToExcluded(): void {
			self::assertSame(
				'INSERT INTO "upsert_conflict_test" ("email", "name") VALUES (:e, :n) ' .
				'ON CONFLICT ("email") DO UPDATE SET "email" = EXCLUDED."email", "name" = EXCLUDED."name"',
				$this->compile($this->parse(self::DEFAULT_LIST_QUERY), 'pgsql')
			);
		}

		public function testSqliteWithNoExplicitListDefaultsToExcluded(): void {
			self::assertSame(
				'INSERT INTO `upsert_conflict_test` (`email`, `name`) VALUES (:e, :n) ' .
				'ON CONFLICT (`email`) DO UPDATE SET `email` = EXCLUDED.`email`, `name` = EXCLUDED.`name`',
				$this->compile($this->parse(self::DEFAULT_LIST_QUERY), 'sqlite')
			);
		}

		public function testMysqlWithNoExplicitListDefaultsToValuesFunction(): void {
			self::assertSame(
				'INSERT INTO `upsert_conflict_test` (`email`, `name`) VALUES (:e, :n) ' .
				'ON DUPLICATE KEY UPDATE `email` = VALUES(`email`), `name` = VALUES(`name`)',
				$this->compile($this->parse(self::DEFAULT_LIST_QUERY), 'mysql')
			);
		}

		public function testSqlServerWithNoExplicitListDefaultsToSourceColumns(): void {
			self::assertSame(
				'MERGE INTO [upsert_conflict_test] AS [__upsert_target] USING (VALUES (:e, :n)) AS [__upsert_source] ([email], [name]) ' .
				'ON [__upsert_target].[email] = [__upsert_source].[email] ' .
				'WHEN MATCHED THEN UPDATE SET [email] = [__upsert_source].[email], [name] = [__upsert_source].[name] ' .
				'WHEN NOT MATCHED THEN INSERT ([email], [name]) VALUES ([__upsert_source].[email], [__upsert_source].[name]);',
				$this->compile($this->parse(self::DEFAULT_LIST_QUERY), 'sqlsrv')
			);
		}

		/**
		 * The target entity's own primary key is always excluded from the
		 * default (no explicit `or replace (...)` list) on-conflict update —
		 * even when it's explicitly part of the appended row, same as it
		 * would be if AppendExecutor::fillGeneratedPrimaryKeys() had added it
		 * for a generated (non-identity) primary-key strategy. Overwriting an
		 * existing row's identity with the row that *would have been
		 * inserted* is never correct — see QuelToSQLUpsert::
		 * buildReferencedSetClause()'s docblock.
		 */
		public function testDefaultListExcludesThePrimaryKeyFromTheOnConflictUpdateOnPostgres(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u (id = :id, email = :e, name = :n) or replace where u.email = :e
			'), $this->em()->getEntityStore()))->parse();

			self::assertSame(
				'INSERT INTO "upsert_conflict_test" ("id", "email", "name") VALUES (:id, :e, :n) ' .
				'ON CONFLICT ("email") DO UPDATE SET "email" = EXCLUDED."email", "name" = EXCLUDED."name"',
				$this->compile($ast, 'pgsql', ['id' => 1, 'e' => 'a@example.com', 'n' => 'Alice'])
			);
		}

		public function testDefaultListExcludesThePrimaryKeyFromTheOnConflictUpdateOnMysql(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u (id = :id, email = :e, name = :n) or replace where u.email = :e
			'), $this->em()->getEntityStore()))->parse();

			self::assertSame(
				'INSERT INTO `upsert_conflict_test` (`id`, `email`, `name`) VALUES (:id, :e, :n) ' .
				'ON DUPLICATE KEY UPDATE `email` = VALUES(`email`), `name` = VALUES(`name`)',
				$this->compile($ast, 'mysql', ['id' => 1, 'e' => 'a@example.com', 'n' => 'Alice'])
			);
		}

		public function testDefaultListExcludesThePrimaryKeyFromTheOnConflictUpdateOnSqlServer(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u (id = :id, email = :e, name = :n) or replace where u.email = :e
			'), $this->em()->getEntityStore()))->parse();

			self::assertSame(
				'MERGE INTO [upsert_conflict_test] AS [__upsert_target] USING (VALUES (:id, :e, :n)) AS [__upsert_source] ([id], [email], [name]) ' .
				'ON [__upsert_target].[email] = [__upsert_source].[email] ' .
				'WHEN MATCHED THEN UPDATE SET [email] = [__upsert_source].[email], [name] = [__upsert_source].[name] ' .
				'WHEN NOT MATCHED THEN INSERT ([id], [email], [name]) VALUES ([__upsert_source].[id], [__upsert_source].[email], [__upsert_source].[name]);',
				$this->compile($ast, 'sqlsrv', ['id' => 1, 'e' => 'a@example.com', 'n' => 'Alice'])
			);
		}

		/**
		 * `name` has no declared unique/primary-key constraint on
		 * UpsertConflictEntity — this no longer compiles to the dialect-native
		 * atomic form (there's no real constraint for the database to enforce
		 * atomicity against), and no longer raises a SemanticException either.
		 * Instead it compiles to the two-statement fallback: an ordinary
		 * `UPDATE ... WHERE u.name = :n` runs first, and the plain INSERT
		 * (identical to a plain, non-upsert append) runs only if that affects
		 * 0 rows. No ON CONFLICT/ON DUPLICATE KEY/MERGE branching applies to
		 * either statement — but the SET clause's target-column qualification
		 * still follows QuelToSQLReplace's own per-dialect rule (see
		 * QuelToSQLReplaceTest), since buildSetClause() is reused unchanged
		 * for an explicit `or replace (...)` list.
		 */
		public function testWhereNotBackedByAUniqueConstraintCompilesToTheNonAtomicFallbackOnMysql(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u (email = :e, name = :n) or replace (name = :n) where u.name = :n
			'), $this->em()->getEntityStore()))->parse();

			$compiled = $this->compileFull($ast, 'mysql');

			self::assertTrue($compiled->hasFallbackUpdate());
			self::assertSame(
				'INSERT INTO `upsert_conflict_test` (`email`, `name`) VALUES (:e, :n)',
				$compiled->primarySql
			);
			self::assertSame(
				'UPDATE `upsert_conflict_test` as `u` SET `u`.`name` = :n WHERE `u`.`name` = :n',
				$compiled->getFallbackUpdateSqlOrFail()
			);
		}

		public function testWhereNotBackedByAUniqueConstraintCompilesToTheNonAtomicFallbackOnPostgres(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u (email = :e, name = :n) or replace (name = :n) where u.name = :n
			'), $this->em()->getEntityStore()))->parse();

			$compiled = $this->compileFull($ast, 'pgsql');

			self::assertTrue($compiled->hasFallbackUpdate());
			self::assertSame(
				'INSERT INTO "upsert_conflict_test" ("email", "name") VALUES (:e, :n)',
				$compiled->primarySql
			);
			// Postgres rejects a qualified column on the LEFT side of SET —
			// same rule QuelToSQLReplaceTest::testPostgresRendersTheSetTargetColumnBare()
			// documents for a standalone `replace`.
			self::assertSame(
				'UPDATE "upsert_conflict_test" as "u" SET "name" = :n WHERE "u"."name" = :n',
				$compiled->getFallbackUpdateSqlOrFail()
			);
		}

		/**
		 * Same non-unique-backed WHERE, but no explicit `or replace (...)`
		 * list: the fallback UPDATE's default SET clause covers every
		 * appended column except the primary key (`id`), using the exact
		 * value compiled for the INSERT — a bare column reference to the
		 * literal parameter, since a plain UPDATE has no EXCLUDED/VALUES()/
		 * source.* concept the way an INSERT...ON CONFLICT does (see
		 * QuelToSQLUpsert::buildDefaultFallbackSetClause()'s docblock).
		 */
		public function testDefaultListFallbackSetsEveryAppendedColumnExceptThePrimaryKey(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u (id = :id, email = :e, name = :n) or replace where u.name = :n
			'), $this->em()->getEntityStore()))->parse();

			$compiled = $this->compileFull($ast, 'mysql', ['id' => 1, 'e' => 'a@example.com', 'n' => 'Alice']);

			self::assertTrue($compiled->hasFallbackUpdate());
			self::assertSame(
				'UPDATE `upsert_conflict_test` as `u` SET `email` = :e, `name` = :n WHERE `u`.`name` = :n',
				$compiled->getFallbackUpdateSqlOrFail()
			);
		}

		/**
		 * A multi-row append can't fall back to a plain update-or-insert: a
		 * single shared WHERE can't identify each literal row's own match the
		 * way a real unique constraint lets the database do per row. Rejected
		 * at compile time rather than silently misapplied to every row.
		 */
		public function testMultiRowAppendWithANonUniqueBackedWhereIsRejectedAtCompileTime(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u
					(email = :e1, name = :n1),
					(email = :e2, name = :n2)
				or replace where u.name = :n1
			'), $this->em()->getEntityStore()))->parse();

			$this->expectException(SemanticException::class);
			$this->compile($ast, 'mysql', ['e1' => 'a', 'n1' => 'A', 'e2' => 'b', 'n2' => 'B']);
		}
	}
