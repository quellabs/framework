<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLReplace;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Dialect-level coverage for a standalone `replace`'s generated UPDATE SQL
	 * — specifically, whether the SET clause's target column is qualified
	 * with the statement's own range alias (see QuelToSQLReplace's docblock).
	 * MySQL/MariaDB/SQL Server accept `SET alias.col = ...`; PostgreSQL/SQLite
	 * reject it as a syntax error, so those two always get the bare column
	 * regardless of dialect. Mirrors QuelToSQLAppendUpsertTest's pattern: the
	 * suite's only live connection is MySQL (exercised end-to-end in
	 * tests/Integration/ReplaceTest.php/PlainTableRangeTest.php), so this is
	 * where the other dialects' generated SQL is actually compared.
	 */
	class QuelToSQLReplaceTest extends TestCase {

		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		private function parse(string $query): AstReplace {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstReplace::class, $ast);
			return $ast;
		}

		private function compile(AstReplace $ast, string $dialect, array $parameters = []): string {
			$em = $this->em();
			$platform = new FakePlatformCapabilities($dialect);
			$compiler = new QuelToSQLReplace($em->getEntityStore(), $platform, $em->getUnitOfWork()->getVersionValueHandler());
			return $compiler->convertToSQL($ast, $parameters);
		}

		private function entityQuery(): AstReplace {
			return $this->parse('
				range of u is App\Entities\UserEntity
				replace u (username = :username) where u.id = :id
			');
		}

		public function testMysqlQualifiesTheSetTargetColumnWithTheRangeAlias(): void {
			self::assertSame(
				'UPDATE `users` as `u` SET `u`.`username` = :username WHERE `u`.`id` = :id',
				$this->compile($this->entityQuery(), 'mysql', ['username' => 'alice', 'id' => 1])
			);
		}

		public function testSqlServerQualifiesTheSetTargetColumnWithTheRangeAlias(): void {
			self::assertSame(
				'UPDATE [users] as [u] SET [u].[username] = :username WHERE [u].[id] = :id',
				$this->compile($this->entityQuery(), 'sqlsrv', ['username' => 'alice', 'id' => 1])
			);
		}

		public function testPostgresRendersTheSetTargetColumnBare(): void {
			// A qualified column on the LEFT side of SET is a syntax error on
			// PostgreSQL — must stay unqualified even though the range is
			// aliased in the UPDATE clause and WHERE is qualified.
			self::assertSame(
				'UPDATE "users" as "u" SET "username" = :username WHERE "u"."id" = :id',
				$this->compile($this->entityQuery(), 'pgsql', ['username' => 'alice', 'id' => 1])
			);
		}

		public function testSqliteRendersTheSetTargetColumnBare(): void {
			// Same restriction as PostgreSQL — see testPostgresRendersTheSetTargetColumnBare().
			self::assertSame(
				'UPDATE `users` as `u` SET `username` = :username WHERE `u`.`id` = :id',
				$this->compile($this->entityQuery(), 'sqlite', ['username' => 'alice', 'id' => 1])
			);
		}

		private function tableQuery(): AstReplace {
			return $this->parse('
				range of a is table customers
				replace a (name = :name) where id = :id
			');
		}

		public function testMysqlQualifiesThePlainTableSetTargetColumnWithTheRangeAlias(): void {
			self::assertSame(
				'UPDATE `customers` as `a` SET `a`.`name` = :name WHERE `a`.`id` = :id',
				$this->compile($this->tableQuery(), 'mysql', ['name' => 'Barry', 'id' => 4])
			);
		}

		public function testPostgresRendersThePlainTableSetTargetColumnBare(): void {
			self::assertSame(
				'UPDATE "customers" as "a" SET "name" = :name WHERE "a"."id" = :id',
				$this->compile($this->tableQuery(), 'pgsql', ['name' => 'Barry', 'id' => 4])
			);
		}
	}
