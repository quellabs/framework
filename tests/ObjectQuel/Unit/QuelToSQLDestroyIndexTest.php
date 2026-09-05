<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDestroyIndex;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Dialect-level coverage for QuelToSQLDestroyIndex (see
	 * objectquel-destroy-index-plan.md). The suite's only live connection
	 * (see tests/Integration/DestroyIndexTest) is MySQL, so this is the only
	 * place pgsql/sqlite/sqlsrv/mariadb generated SQL is compared — in
	 * particular emulateMysqlIfExists(), the dynamic-SQL workaround for
	 * plain MySQL never supporting `DROP INDEX IF EXISTS`.
	 */
	class QuelToSQLDestroyIndexTest extends TestCase {

		private function parse(string $query): AstDestroyIndex {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstDestroyIndex::class, $ast);
			return $ast;
		}

		/** @return string[] */
		private function compile(AstDestroyIndex $ast, string $dialect): array {
			return (new QuelToSQLDestroyIndex(new FakePlatformCapabilities($dialect)))->convertToSQL($ast);
		}

		public function testPlainDestroyAcrossDialects(): void {
			$ast = $this->parse('destroy archive_log_email_idx on ArchiveLog');

			self::assertSame(['DROP INDEX `archive_log_email_idx` ON `ArchiveLog`'], $this->compile($ast, 'mysql'));
			self::assertSame(['DROP INDEX `archive_log_email_idx` ON `ArchiveLog`'], $this->compile($ast, 'mariadb'));
			self::assertSame(['DROP INDEX "archive_log_email_idx"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['DROP INDEX `archive_log_email_idx`'], $this->compile($ast, 'sqlite'));
			self::assertSame(['DROP INDEX [archive_log_email_idx] ON [ArchiveLog]'], $this->compile($ast, 'sqlsrv'));
		}

		public function testIfExistsNativelyOnNonMysqlDialects(): void {
			$ast = $this->parse('destroy archive_log_email_idx on ArchiveLog if exists');

			self::assertSame(
				['DROP INDEX IF EXISTS `archive_log_email_idx` ON `ArchiveLog`'],
				$this->compile($ast, 'mariadb')
			);
			self::assertSame(['DROP INDEX IF EXISTS "archive_log_email_idx"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['DROP INDEX IF EXISTS `archive_log_email_idx`'], $this->compile($ast, 'sqlite'));
			self::assertSame(
				['DROP INDEX IF EXISTS [archive_log_email_idx] ON [ArchiveLog]'],
				$this->compile($ast, 'sqlsrv')
			);
		}

		public function testPlainMysqlEmulatesIfExistsViaDynamicSql(): void {
			$ast = $this->parse('destroy archive_log_email_idx on ArchiveLog if exists');

			$statements = $this->compile($ast, 'mysql');

			self::assertSame(
				[
					"SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ArchiveLog' AND index_name = 'archive_log_email_idx')",
					"SET @sql = IF(@idx_exists > 0, 'DROP INDEX `archive_log_email_idx` ON `ArchiveLog`', 'DO 0')",
					'PREPARE stmt FROM @sql',
					'EXECUTE stmt',
					'DEALLOCATE PREPARE stmt',
				],
				$statements
			);
		}

		public function testTargetIsALiteralTableNameNotAnEntityLookup(): void {
			// No range declaration, no EntityStore involved — same as
			// create/destroy's table name.
			$ast = $this->parse('destroy user_username_idx on UserEntity');

			self::assertSame(
				['DROP INDEX `user_username_idx` ON `UserEntity`'],
				$this->compile($ast, 'mysql')
			);
		}

		public function testIgnoresRangeDeclarationBeforeDestroyIndex(): void {
			$ast = $this->parse('
				range of x is UserEntity
				destroy archive_log_email_idx on ArchiveLog
			');

			self::assertSame(['DROP INDEX `archive_log_email_idx` ON `ArchiveLog`'], $this->compile($ast, 'mysql'));
		}

		public function testUnqualifiedDestroyStillParsesAsTheTableForm(): void {
			// A bare `destroy Name`, with nothing trailing, is always the
			// table form — shape, not a keyword, is the discriminator.
			$ast = (new Parser(new Lexer('destroy ArchiveLog')))->parse();

			self::assertNotInstanceOf(AstDestroyIndex::class, $ast);
		}

		public function testSqlServerFulltextDropClearsTheExtendedPropertyThenDropsTheIndex(): void {
			// Only ever called by DestroyIndexExecutor once it has already
			// confirmed the name via the extended-property tag — the drop
			// itself is unconditional (see convertToSqlServerFulltextDropSQL()).
			$ast = $this->parse('destroy article_fulltext_idx on ArticleEntity');

			$statements = (new QuelToSQLDestroyIndex(new FakePlatformCapabilities('sqlsrv')))
				->convertToSqlServerFulltextDropSQL($ast);

			self::assertSame(
				[
					"IF EXISTS (SELECT 1 FROM sys.extended_properties WHERE major_id = OBJECT_ID('ArticleEntity') AND minor_id = 0 AND name = 'quel_fulltext_index_name') " .
					"EXEC sp_dropextendedproperty @name = 'quel_fulltext_index_name', @level0type = N'SCHEMA', @level0name = N'dbo', @level1type = N'TABLE', @level1name = 'ArticleEntity'",
					'DROP FULLTEXT INDEX ON [ArticleEntity]',
				],
				$statements
			);
		}

		public function testSqliteFts5DropRemovesTheSyncTriggersThenTheVirtualTable(): void {
			// Only ever called by DestroyIndexExecutor once it has already
			// confirmed $indexName is an FTS5 virtual table built against
			// this table (see convertToSqliteFts5DropSQL()).
			$ast = $this->parse('destroy article_fulltext_idx on ArticleEntity');

			$statements = (new QuelToSQLDestroyIndex(new FakePlatformCapabilities('sqlite')))
				->convertToSqliteFts5DropSQL($ast);

			self::assertSame(
				[
					'DROP TRIGGER `article_fulltext_idx_ai`',
					'DROP TRIGGER `article_fulltext_idx_ad`',
					'DROP TRIGGER `article_fulltext_idx_au`',
					'DROP TABLE `article_fulltext_idx`',
				],
				$statements
			);
		}
	}
