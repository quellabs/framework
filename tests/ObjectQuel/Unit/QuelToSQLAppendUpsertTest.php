<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
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
	 * QuelToSQLCreateTest) since an upsert's conflict target is validated
	 * against App\Entities\UpsertConflictEntity's declared @Orm\UniqueIndex.
	 */
	class QuelToSQLAppendUpsertTest extends TestCase {

		private const string QUERY = <<<'QUEL'
			range of u is App\Entities\UpsertConflictEntity
			append to u (email = :e, name = :n) or replace (name = :n) where u.email = :e
			QUEL;

		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		private function parse(): AstAppend {
			$ast = (new Parser(new Lexer(self::QUERY)))->parse();
			self::assertInstanceOf(AstAppend::class, $ast);
			return $ast;
		}

		private function compile(AstAppend $ast, string $dialect, array $parameters = ['e' => 'a@example.com', 'n' => 'Alice']): string {
			$em = $this->em();
			$platform = new FakePlatformCapabilities($dialect);
			$replaceCompiler = new QuelToSQLReplace($em->getEntityStore(), $platform, $em->getUnitOfWork()->getVersionValueHandler());
			$upsertCompiler = new QuelToSQLUpsert($em->getEntityStore(), $platform, $replaceCompiler);
			$compiler = new QuelToSQLAppend($em->getEntityStore(), $em, $platform, $upsertCompiler);
			return $compiler->convertToSQL($ast, $parameters);
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
			')))->parse();

			self::assertSame(
				'MERGE INTO [upsert_conflict_test] AS [__upsert_target] USING (VALUES (:e1, :n1), (:e2, :n2)) AS [__upsert_source] ([email], [name]) ' .
				'ON [__upsert_target].[email] = [__upsert_source].[email] ' .
				'WHEN MATCHED THEN UPDATE SET [name] = :n1 ' .
				'WHEN NOT MATCHED THEN INSERT ([email], [name]) VALUES ([__upsert_source].[email], [__upsert_source].[name]);',
				$this->compile($ast, 'sqlsrv', ['e1' => 'a', 'n1' => 'A', 'e2' => 'b', 'n2' => 'B'])
			);
		}

		public function testRejectsAConflictTargetNotBackedByAUniqueConstraint(): void {
			$ast = (new Parser(new Lexer('
				range of u is App\Entities\UpsertConflictEntity
				append to u (email = :e, name = :n) or replace (name = :n) where u.name = :n
			')))->parse();

			$this->expectException(\Quellabs\ObjectQuel\Exception\SemanticException::class);
			$this->compile($ast, 'mysql');
		}
	}
