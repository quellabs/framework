<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLAlter;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Dialect-level coverage for `alter Name (op {, op})`'s column and
	 * primary-key sub-operations (see objectquel-alter-table-design.md).
	 * Index sub-operations (`add index`/`drop index`) are sugar over
	 * AstCreateIndex/AstDestroyIndex and are covered instead by
	 * AlterTableExecutorTest, which is where the assembly happens — see
	 * QuelToSQLAlter's class docblock.
	 */
	class QuelToSQLAlterTest extends TestCase {

		private function parse(string $query): AstAlterTable {
			$ast = (new Parser(new Lexer($query), $GLOBALS['test_em']->getEntityStore()))->parse();
			self::assertInstanceOf(AstAlterTable::class, $ast);
			return $ast;
		}

		private function compile(AstAlterTable $ast, string $dialect, array $existingPk = [], ?string $existingPkName = null): array {
			return (new QuelToSQLAlter(new FakePlatformCapabilities($dialect)))->convertToSQL($ast, $existingPk, $existingPkName);
		}

		public function testAddColumnAcrossDialects(): void {
			$ast = $this->parse('alter Posts (add view_count = integer not null)');

			self::assertSame(['ALTER TABLE `Posts` ADD COLUMN `view_count` INT NOT NULL'], $this->compile($ast, 'mysql'));
			self::assertSame(['ALTER TABLE "Posts" ADD COLUMN "view_count" INTEGER NOT NULL'], $this->compile($ast, 'pgsql'));
			self::assertSame(['ALTER TABLE `Posts` ADD COLUMN `view_count` INTEGER NOT NULL'], $this->compile($ast, 'sqlite'));
			self::assertSame(['ALTER TABLE [Posts] ADD [view_count] INT NOT NULL'], $this->compile($ast, 'sqlsrv'));
		}

		public function testAddIdentityColumnOnSqliteIsRejected(): void {
			$ast = $this->parse('alter Posts (add id = integer identity)');

			$this->expectException(QuelException::class);
			$this->compile($ast, 'sqlite');
		}

		public function testDropColumnAcrossDialects(): void {
			$ast = $this->parse('alter Posts (drop legacy_flag)');

			self::assertSame(['ALTER TABLE `Posts` DROP COLUMN `legacy_flag`'], $this->compile($ast, 'mysql'));
			self::assertSame(['ALTER TABLE "Posts" DROP COLUMN "legacy_flag"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['ALTER TABLE `Posts` DROP COLUMN `legacy_flag`'], $this->compile($ast, 'sqlite'));
			self::assertSame(['ALTER TABLE [Posts] DROP COLUMN [legacy_flag]'], $this->compile($ast, 'sqlsrv'));
		}

		public function testRenameColumnAcrossDialects(): void {
			$ast = $this->parse('alter Posts (rename old_name to new_name)');

			self::assertSame(['ALTER TABLE `Posts` RENAME COLUMN `old_name` TO `new_name`'], $this->compile($ast, 'mysql'));
			self::assertSame(['ALTER TABLE "Posts" RENAME COLUMN "old_name" TO "new_name"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['ALTER TABLE `Posts` RENAME COLUMN `old_name` TO `new_name`'], $this->compile($ast, 'sqlite'));
			self::assertSame(["EXEC sp_rename N'Posts.old_name', N'new_name', N'COLUMN'"], $this->compile($ast, 'sqlsrv'));
		}

		public function testRetypeColumnOnMysql(): void {
			$ast = $this->parse('alter Posts (retype price = decimal(10,2) not null)');

			self::assertSame(
				['ALTER TABLE `Posts` MODIFY COLUMN `price` DECIMAL(10,2) NOT NULL'],
				$this->compile($ast, 'mysql')
			);
		}

		public function testRetypeColumnOnPostgres(): void {
			$ast = $this->parse('alter Posts (retype price = decimal(10,2) not null)');

			self::assertSame(
				[
					'ALTER TABLE "Posts" ALTER COLUMN "price" TYPE DECIMAL(10,2) USING "price"::DECIMAL(10,2)',
					'ALTER TABLE "Posts" ALTER COLUMN "price" SET NOT NULL',
					'ALTER TABLE "Posts" ALTER COLUMN "price" DROP IDENTITY IF EXISTS',
				],
				$this->compile($ast, 'pgsql')
			);
		}

		public function testRetypeColumnToNullableOnPostgresDropsNotNull(): void {
			$ast = $this->parse('alter Posts (retype price = decimal(10,2))');

			$statements = $this->compile($ast, 'pgsql');
			self::assertSame('ALTER TABLE "Posts" ALTER COLUMN "price" DROP NOT NULL', $statements[1]);
		}

		public function testRetypeColumnOnSqlServer(): void {
			$ast = $this->parse('alter Posts (retype price = decimal(10,2) not null)');

			self::assertSame(
				['ALTER TABLE [Posts] ALTER COLUMN [price] DECIMAL(10,2) NOT NULL'],
				$this->compile($ast, 'sqlsrv')
			);
		}

		public function testRetypeToIdentityOnSqlServerIsRejected(): void {
			$ast = $this->parse('alter Posts (retype id = integer identity)');

			$this->expectException(QuelException::class);
			$this->compile($ast, 'sqlsrv');
		}

		public function testRetypeOnSqliteIsRejected(): void {
			$ast = $this->parse('alter Posts (retype price = decimal(10,2))');

			$this->expectException(QuelException::class);
			$this->compile($ast, 'sqlite');
		}

		public function testSetPrimaryKeyWithNoExistingKeyJustAdds(): void {
			$ast = $this->parse('alter Posts (primary key (id))');

			self::assertSame(['ALTER TABLE `Posts` ADD PRIMARY KEY (`id`)'], $this->compile($ast, 'mysql'));
		}

		public function testSetPrimaryKeyReplacesAnExistingOneOnMysql(): void {
			$ast = $this->parse('alter Posts (primary key (id))');

			self::assertSame(
				['ALTER TABLE `Posts` DROP PRIMARY KEY', 'ALTER TABLE `Posts` ADD PRIMARY KEY (`id`)'],
				$this->compile($ast, 'mysql', ['old_id'])
			);
		}

		public function testSetPrimaryKeyReplacesAnExistingOneOnPostgresUsingResolvedConstraintName(): void {
			$ast = $this->parse('alter Posts (primary key (id))');

			self::assertSame(
				['ALTER TABLE "Posts" DROP CONSTRAINT "posts_pkey"', 'ALTER TABLE "Posts" ADD PRIMARY KEY ("id")'],
				$this->compile($ast, 'pgsql', ['old_id'], 'posts_pkey')
			);
		}

		public function testSetPrimaryKeyOnSqliteIsRejected(): void {
			$ast = $this->parse('alter Posts (primary key (id))');

			$this->expectException(QuelException::class);
			$this->compile($ast, 'sqlite');
		}

		public function testDropPrimaryKeyOnMysql(): void {
			$ast = $this->parse('alter Posts (drop primary key)');

			self::assertSame(['ALTER TABLE `Posts` DROP PRIMARY KEY'], $this->compile($ast, 'mysql', ['id']));
		}

		public function testDropPrimaryKeyWithNoExistingKeyIsRejected(): void {
			$ast = $this->parse('alter Posts (drop primary key)');

			$this->expectException(QuelException::class);
			$this->compile($ast, 'mysql');
		}

		public function testDropPrimaryKeyOnSqlServerNeedsAResolvedConstraintName(): void {
			$ast = $this->parse('alter Posts (drop primary key)');

			$this->expectException(QuelException::class);
			// Existing PK columns resolved, but no constraint name — an
			// inconsistency this compiler refuses to guess through.
			$this->compile($ast, 'sqlsrv', ['id'], null);
		}

		public function testMultipleColumnSubOperationsCompileInDeclarationOrder(): void {
			$ast = $this->parse('alter Posts (add a = integer, drop b, rename c to d, retype e = integer)');

			self::assertSame(
				[
					'ALTER TABLE `Posts` ADD COLUMN `a` INT',
					'ALTER TABLE `Posts` DROP COLUMN `b`',
					'ALTER TABLE `Posts` RENAME COLUMN `c` TO `d`',
					'ALTER TABLE `Posts` MODIFY COLUMN `e` INT',
				],
				$this->compile($ast, 'mysql')
			);
		}

		public function testIndexSubOperationsCompileToNothingHere(): void {
			// Index sub-operations are compiled by AlterTableExecutor
			// instead (see class docblock) — this compiler skips them.
			$ast = $this->parse('alter Posts (add index idx_a (a))');

			self::assertSame([], $this->compile($ast, 'mysql'));
		}
	}
