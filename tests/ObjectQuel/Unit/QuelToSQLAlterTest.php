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

		public function testAddForeignKeyWithDefaultActionsAcrossDialects(): void {
			$ast = $this->parse('alter Posts (add foreign key (author_id) references Users (id))');

			self::assertSame(
				['ALTER TABLE `Posts` ADD CONSTRAINT `fk_Posts_author_id` FOREIGN KEY (`author_id`) REFERENCES `Users` (`id`) ON DELETE RESTRICT ON UPDATE NO ACTION'],
				$this->compile($ast, 'mysql')
			);
			self::assertSame(
				['ALTER TABLE "Posts" ADD CONSTRAINT "fk_Posts_author_id" FOREIGN KEY ("author_id") REFERENCES "Users" ("id") ON DELETE RESTRICT ON UPDATE NO ACTION'],
				$this->compile($ast, 'pgsql')
			);
			self::assertSame(
				// SQL Server's FOREIGN KEY clause has no RESTRICT keyword —
				// NO ACTION is the behavioral equivalent (see
				// ForeignKeyActionNormalizer).
				['ALTER TABLE [Posts] ADD CONSTRAINT [fk_Posts_author_id] FOREIGN KEY ([author_id]) REFERENCES [Users] ([id]) ON DELETE NO ACTION ON UPDATE NO ACTION'],
				$this->compile($ast, 'sqlsrv')
			);
		}

		public function testAddForeignKeyWithExplicitActions(): void {
			$ast = $this->parse('alter Posts (add foreign key (author_id) references Users (id) on delete cascade on update set null)');

			self::assertSame(
				['ALTER TABLE `Posts` ADD CONSTRAINT `fk_Posts_author_id` FOREIGN KEY (`author_id`) REFERENCES `Users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL'],
				$this->compile($ast, 'mysql')
			);
		}

		/**
		 * RESTRICT is valid MySQL/PostgreSQL/SQLite syntax but not T-SQL's
		 * FOREIGN KEY clause — an explicit RESTRICT normalizes to NO ACTION
		 * on sqlsrv, same as the implicit default (see
		 * testAddForeignKeyWithDefaultActionsAcrossDialects).
		 */
		public function testAddForeignKeyWithExplicitRestrictNormalizesToNoActionOnSqlServer(): void {
			$ast = $this->parse('alter Posts (add foreign key (author_id) references Users (id) on delete restrict on update cascade)');

			self::assertSame(
				['ALTER TABLE [Posts] ADD CONSTRAINT [fk_Posts_author_id] FOREIGN KEY ([author_id]) REFERENCES [Users] ([id]) ON DELETE NO ACTION ON UPDATE CASCADE'],
				$this->compile($ast, 'sqlsrv')
			);
		}

		/**
		 * A table+column pair long enough that the derived
		 * `fk_{table}_{column}` name exceeds every supported dialect's
		 * identifier length limit is rejected at compile time (see
		 * ForeignKeyConstraintNamer::nameOrThrow()) instead of failing with
		 * a confusing "identifier name is too long" error from the database.
		 */
		public function testAddForeignKeyWithADerivedConstraintNameThatIsTooLongIsRejected(): void {
			$longTableName = str_repeat('a', 40);
			$longColumnName = str_repeat('b', 40);
			$ast = $this->parse("alter {$longTableName} (add foreign key ({$longColumnName}) references Users (id))");

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('exceeding the 63-character identifier limit');

			$this->compile($ast, 'mysql');
		}

		public function testAddForeignKeyOnSqliteIsRejected(): void {
			$ast = $this->parse('alter Posts (add foreign key (author_id) references Users (id))');

			$this->expectException(QuelException::class);
			$this->compile($ast, 'sqlite');
		}

		public function testDropForeignKeyUsesDropForeignKeyOnMysql(): void {
			$ast = $this->parse('alter Posts (drop foreign key (author_id))');

			self::assertSame(
				['ALTER TABLE `Posts` DROP FOREIGN KEY `fk_Posts_author_id`'],
				$this->compile($ast, 'mysql')
			);
		}

		public function testDropForeignKeyUsesDropConstraintOnPostgresAndSqlServer(): void {
			$ast = $this->parse('alter Posts (drop foreign key (author_id))');

			self::assertSame(
				['ALTER TABLE "Posts" DROP CONSTRAINT "fk_Posts_author_id"'],
				$this->compile($ast, 'pgsql')
			);
			self::assertSame(
				['ALTER TABLE [Posts] DROP CONSTRAINT [fk_Posts_author_id]'],
				$this->compile($ast, 'sqlsrv')
			);
		}

		public function testDropForeignKeyOnSqliteIsRejected(): void {
			$ast = $this->parse('alter Posts (drop foreign key (author_id))');

			$this->expectException(QuelException::class);
			$this->compile($ast, 'sqlite');
		}

		/**
		 * `add foreign key` always compiles after column/PK sub-operations,
		 * regardless of declared order, so it can reference a column added
		 * earlier in the same statement without the ADD CONSTRAINT
		 * statement running before that column exists.
		 */
		public function testAddForeignKeyCompilesAfterAddColumnRegardlessOfDeclarationOrder(): void {
			$ast = $this->parse('alter Posts (add foreign key (author_id) references Users (id), add author_id = integer not null)');

			self::assertSame(
				[
					'ALTER TABLE `Posts` ADD COLUMN `author_id` INT NOT NULL',
					'ALTER TABLE `Posts` ADD CONSTRAINT `fk_Posts_author_id` FOREIGN KEY (`author_id`) REFERENCES `Users` (`id`) ON DELETE RESTRICT ON UPDATE NO ACTION',
				],
				$this->compile($ast, 'mysql')
			);
		}

		/**
		 * `drop foreign key` always compiles before column/PK
		 * sub-operations, regardless of declared order, so a column it
		 * constrains can be dropped afterward in the same statement without
		 * the database rejecting the drop while the constraint still
		 * references it.
		 */
		public function testDropForeignKeyCompilesBeforeDropColumnRegardlessOfDeclarationOrder(): void {
			$ast = $this->parse('alter Posts (drop author_id, drop foreign key (author_id))');

			self::assertSame(
				[
					'ALTER TABLE `Posts` DROP FOREIGN KEY `fk_Posts_author_id`',
					'ALTER TABLE `Posts` DROP COLUMN `author_id`',
				],
				$this->compile($ast, 'mysql')
			);
		}
	}
