<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDelete;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Dialect-level coverage for a standalone `delete`'s generated DELETE SQL.
	 * Mirrors QuelToSQLReplaceTest's pattern: the suite's only live connection
	 * is MySQL (exercised end-to-end in tests/Integration/DeleteTest.php), so
	 * this is where the WHERE clause's generated SQL is actually compared.
	 */
	class QuelToSQLDeleteTest extends TestCase {

		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		private function parse(string $query): AstDelete {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstDelete::class, $ast);
			return $ast;
		}

		private function compile(AstDelete $ast, string $dialect, array $parameters = []): string {
			$platform = new FakePlatformCapabilities($dialect);
			$compiler = new QuelToSQLDelete($this->em()->getEntityStore(), $platform);
			return $compiler->convertToSQL($ast, $parameters);
		}

		public function testMysqlCompilesAPlainWhereClause(): void {
			$ast = $this->parse('
				range of u is App\Entities\UserEntity
				delete u where u.id = :id
			');

			self::assertSame(
				'DELETE FROM `users` as `u` WHERE `u`.`id` = :id',
				$this->compile($ast, 'mysql', ['id' => 1])
			);
		}

		/**
		 * `any(...)` in a WHERE clause must compile to a bare EXISTS(...),
		 * not `CASE WHEN EXISTS(...) THEN 1 ELSE 0 END` — the latter is what
		 * BuildSqlFromAst's 'VALUES' mode produces (see
		 * ProcessAggregate::handleAny()), and PostgreSQL rejects an integer
		 * CASE result in a WHERE clause since it isn't implicitly boolean
		 * there. The WHERE clause must be compiled in 'WHERE' mode, same as
		 * QuelToSQLRetrieve's own WHERE clause.
		 */
		public function testAnyInWhereClauseCompilesToABareExistsNotACaseExpression(): void {
			$ast = $this->parse("
				range of o is App\\Entities\\PostEntity
				delete o where any(o.id where o.title = 'completed')
			");

			$sql = $this->compile($ast, 'pgsql');

			self::assertStringContainsString('WHERE EXISTS (', $sql);
			self::assertStringNotContainsString('CASE WHEN EXISTS', $sql);
		}
	}
