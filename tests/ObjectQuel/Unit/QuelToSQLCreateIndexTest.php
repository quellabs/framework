<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLCreateIndex;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Parser- and dialect-level coverage for `index [unique|fulltext] on
	 * Table is index_name (...)` (see objectquel-create-index-plan.md).
	 * `unique` and `fulltext` occupy the same grammar slot right after
	 * `index`, so at most one is ever present — no "unique fulltext" case to
	 * guard against. No EntityStore involved — $tableName is a literal name,
	 * same as create/destroy — so, unlike upsert's parser tests, this covers
	 * both grammar and generated SQL in one file, mirroring
	 * QuelToSQLCreateTest/QuelToSQLDestroyTest's pattern. The suite's only
	 * live connection (see tests/Integration/CreateIndexTest) is MySQL, so
	 * this is the only place pgsql/sqlite/sqlsrv generated SQL is compared.
	 */
	class QuelToSQLCreateIndexTest extends TestCase {

		private function parse(string $query): AstCreateIndex {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstCreateIndex::class, $ast);
			return $ast;
		}

		/**
		 * The plain/unique case always compiles to exactly one statement —
		 * unwraps it for these tests' convenience. Fulltext (which can
		 * compile to several) is tested separately below via
		 * convertToSQL()'s full array return.
		 */
		private function compile(AstCreateIndex $ast, string $dialect): string {
			$statements = (new QuelToSQLCreateIndex(new FakePlatformCapabilities($dialect)))->convertToSQL($ast);
			self::assertCount(1, $statements);
			return $statements[0];
		}

		public function testPlainIndexAcrossDialects(): void {
			$ast = $this->parse('index on ArchiveLog is archive_log_created_idx (created_at)');

			self::assertSame('CREATE INDEX `archive_log_created_idx` ON `ArchiveLog` (`created_at`)', $this->compile($ast, 'mysql'));
			self::assertSame('CREATE INDEX "archive_log_created_idx" ON "ArchiveLog" ("created_at")', $this->compile($ast, 'pgsql'));
			self::assertSame('CREATE INDEX `archive_log_created_idx` ON `ArchiveLog` (`created_at`)', $this->compile($ast, 'sqlite'));
			self::assertSame('CREATE INDEX [archive_log_created_idx] ON [ArchiveLog] ([created_at])', $this->compile($ast, 'sqlsrv'));
		}

		public function testUniqueIndexAcrossDialects(): void {
			$ast = $this->parse('index unique on ArchiveLog is archive_log_email_idx (email)');

			self::assertSame('CREATE UNIQUE INDEX `archive_log_email_idx` ON `ArchiveLog` (`email`)', $this->compile($ast, 'mysql'));
			self::assertSame('CREATE UNIQUE INDEX "archive_log_email_idx" ON "ArchiveLog" ("email")', $this->compile($ast, 'pgsql'));
			self::assertSame('CREATE UNIQUE INDEX `archive_log_email_idx` ON `ArchiveLog` (`email`)', $this->compile($ast, 'sqlite'));
			self::assertSame('CREATE UNIQUE INDEX [archive_log_email_idx] ON [ArchiveLog] ([email])', $this->compile($ast, 'sqlsrv'));
		}

		public function testMultiColumnIndexAcrossDialects(): void {
			$ast = $this->parse('index unique on ArchiveLog is archive_log_composite_idx (tenant_id, email)');

			self::assertSame('CREATE UNIQUE INDEX `archive_log_composite_idx` ON `ArchiveLog` (`tenant_id`, `email`)', $this->compile($ast, 'mysql'));
			self::assertSame('CREATE UNIQUE INDEX "archive_log_composite_idx" ON "ArchiveLog" ("tenant_id", "email")', $this->compile($ast, 'pgsql'));
			self::assertSame('CREATE UNIQUE INDEX [archive_log_composite_idx] ON [ArchiveLog] ([tenant_id], [email])', $this->compile($ast, 'sqlsrv'));
		}

		public function testTargetIsALiteralTableNameNotAnEntityLookup(): void {
			// No range declaration, no EntityStore involved — exactly like
			// create/destroy, `index`'s table name is used as-is, whether or
			// not it happens to also be an Entity class name.
			$ast = $this->parse('index on UserEntity is user_username_idx (username)');

			self::assertSame(
				'CREATE INDEX `user_username_idx` ON `UserEntity` (`username`)',
				$this->compile($ast, 'mysql')
			);
		}

		public function testIgnoresRangeDeclarationBeforeIndex(): void {
			$ast = $this->parse('
				range of x is UserEntity
				index on ArchiveLog is archive_log_email_idx (email)
			');

			self::assertSame('CREATE INDEX `archive_log_email_idx` ON `ArchiveLog` (`email`)', $this->compile($ast, 'mysql'));
		}

		public function testRejectsADuplicateColumnInTheColumnList(): void {
			$this->expectException(ParserException::class);
			$this->parse('index on ArchiveLog is archive_log_bad_idx (email, email)');
		}

		public function testParsesFulltextType(): void {
			$ast = $this->parse('index fulltext on ArticleEntity is article_body_fulltext_idx (body)');
			self::assertSame('fulltext', $ast->getType());
			self::assertFalse($ast->isUnique());
		}

		public function testMysqlFulltextCompilesToCreateFulltextIndex(): void {
			$ast = $this->parse('index fulltext on ArticleEntity is article_fulltext_idx (title, body)');

			self::assertSame(
				['CREATE FULLTEXT INDEX `article_fulltext_idx` ON `ArticleEntity` (`title`, `body`)'],
				(new QuelToSQLCreateIndex(new FakePlatformCapabilities('mysql')))->convertToSQL($ast)
			);
			self::assertSame(
				['CREATE FULLTEXT INDEX `article_fulltext_idx` ON `ArticleEntity` (`title`, `body`)'],
				(new QuelToSQLCreateIndex(new FakePlatformCapabilities('mariadb')))->convertToSQL($ast)
			);
		}

		public function testPostgresFulltextCompilesToAGinExpressionIndex(): void {
			$ast = $this->parse('index fulltext on ArticleEntity is article_fulltext_idx (title, body)');

			self::assertSame(
				[
					'CREATE INDEX "article_fulltext_idx" ON "ArticleEntity" USING GIN ' .
					'(to_tsvector(\'english\', coalesce("title", \'\') || \' \' || coalesce("body", \'\')))',
				],
				(new QuelToSQLCreateIndex(new FakePlatformCapabilities('pgsql')))->convertToSQL($ast)
			);
		}

		public function testSqlServerFulltextBootstrapsTheCatalogAndUsesTheResolvedKeyIndex(): void {
			$ast = $this->parse('index fulltext on ArticleEntity is article_fulltext_idx (title, body)');

			$statements = (new QuelToSQLCreateIndex(new FakePlatformCapabilities('sqlsrv')))
				->convertToSQL($ast, null, 'PK_ArticleEntity');

			self::assertSame(
				[
					"IF NOT EXISTS (SELECT 1 FROM sys.fulltext_catalogs WHERE name = 'quel_fulltext_catalog') " .
					"CREATE FULLTEXT CATALOG [quel_fulltext_catalog] AS DEFAULT",
					'CREATE FULLTEXT INDEX ON [ArticleEntity] ([title], [body]) KEY INDEX [PK_ArticleEntity] ON [quel_fulltext_catalog]',
					"IF EXISTS (SELECT 1 FROM sys.extended_properties WHERE major_id = OBJECT_ID('ArticleEntity') AND minor_id = 0 AND name = 'quel_fulltext_index_name') " .
					"EXEC sp_updateextendedproperty @name = 'quel_fulltext_index_name', @value = 'article_fulltext_idx', @level0type = N'SCHEMA', @level0name = N'dbo', @level1type = N'TABLE', @level1name = 'ArticleEntity' " .
					"ELSE EXEC sp_addextendedproperty @name = 'quel_fulltext_index_name', @value = 'article_fulltext_idx', @level0type = N'SCHEMA', @level0name = N'dbo', @level1type = N'TABLE', @level1name = 'ArticleEntity'",
				],
				$statements
			);
		}

		public function testSqlServerFulltextTagsTheIndexNameAsAnExtendedProperty(): void {
			// The QUEL index name has no equivalent in `CREATE FULLTEXT
			// INDEX` itself (T-SQL fulltext indexes are unnamed, one per
			// table) — it's tagged as a table-level extended property
			// instead, so QuelToSQLDestroyIndex can later correlate a
			// `destroy Name on Table` against the real object (see
			// objectquel-destroy-index-plan.md).
			$ast = $this->parse('index fulltext on ArticleEntity is article_fulltext_idx (title, body)');

			$statements = (new QuelToSQLCreateIndex(new FakePlatformCapabilities('sqlsrv')))
				->convertToSQL($ast, null, 'PK_ArticleEntity');

			self::assertCount(3, $statements);
			self::assertStringContainsString('sp_addextendedproperty', $statements[2]);
			self::assertStringContainsString("'quel_fulltext_index_name'", $statements[2]);
			self::assertStringContainsString("'article_fulltext_idx'", $statements[2]);
		}

		public function testSqliteFulltextCreatesAnFts5VirtualTableWithSyncTriggers(): void {
			$ast = $this->parse('index fulltext on ArticleEntity is article_fulltext_idx (title, body)');

			$statements = (new QuelToSQLCreateIndex(new FakePlatformCapabilities('sqlite')))
				->convertToSQL($ast, 'id', null);

			self::assertSame(
				"CREATE VIRTUAL TABLE `article_fulltext_idx` USING fts5(`title`, `body`, content='ArticleEntity', content_rowid='id')",
				$statements[0]
			);
			self::assertSame(
				'CREATE TRIGGER `article_fulltext_idx_ai` AFTER INSERT ON `ArticleEntity` BEGIN ' .
				'INSERT INTO `article_fulltext_idx`(rowid, `title`, `body`) VALUES (new.`id`, new.`title`, new.`body`); END',
				$statements[1]
			);
			self::assertSame(
				'CREATE TRIGGER `article_fulltext_idx_ad` AFTER DELETE ON `ArticleEntity` BEGIN ' .
				'INSERT INTO `article_fulltext_idx`(`article_fulltext_idx`, rowid, `title`, `body`) ' .
				'VALUES (\'delete\', old.`id`, old.`title`, old.`body`); END',
				$statements[2]
			);
			self::assertSame(
				'CREATE TRIGGER `article_fulltext_idx_au` AFTER UPDATE ON `ArticleEntity` BEGIN ' .
				'INSERT INTO `article_fulltext_idx`(`article_fulltext_idx`, rowid, `title`, `body`) ' .
				'VALUES (\'delete\', old.`id`, old.`title`, old.`body`); ' .
				'INSERT INTO `article_fulltext_idx`(rowid, `title`, `body`) VALUES (new.`id`, new.`title`, new.`body`); END',
				$statements[3]
			);
			self::assertCount(4, $statements);
		}

		public function testCannotWriteBothUniqueAndFulltextOnTheSameIndex(): void {
			// `unique` and `fulltext` occupy the same grammar slot right
			// after `index` — the parser matches at most one keyword there,
			// so writing both leaves the second one where `on` is expected.
			// This is a plain syntax error (unexpected token where `on` is
			// expected), not a semantic "combination rejected" check — there
			// is nothing left to reject.
			$this->expectException(LexerException::class);
			$this->parse('index unique fulltext on ArchiveLog is x (email)');
		}

		public function testRejectsAMissingColumnList(): void {
			// Truncated input — the lexer hits EOF looking for the expected
			// '(', not a semantic/grammar error, hence LexerException rather
			// than ParserException here.
			$this->expectException(LexerException::class);
			$this->parse('index on ArchiveLog is archive_log_email_idx');
		}
	}
