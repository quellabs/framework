<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstHideIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstShowIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLIndexVisibility;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Dialect-level coverage for QuelToSQLIndexVisibility (`hide Name on
	 * Table` / `show Name on Table`). The suite's only live connection (see
	 * tests/Integration) is MySQL, so this is the only place MariaDB's
	 * IGNORED/NOT IGNORED wording and the pgsql/sqlite/sqlsrv rejection are
	 * exercised.
	 */
	class QuelToSQLIndexVisibilityTest extends TestCase {

		private function parseHide(string $query): AstHideIndex {
			$ast = (new Parser(new Lexer($query), $GLOBALS['test_em']->getEntityStore()))->parse();
			self::assertInstanceOf(AstHideIndex::class, $ast);
			return $ast;
		}

		private function parseShow(string $query): AstShowIndex {
			$ast = (new Parser(new Lexer($query), $GLOBALS['test_em']->getEntityStore()))->parse();
			self::assertInstanceOf(AstShowIndex::class, $ast);
			return $ast;
		}

		public function testHideCompilesToInvisibleOnMysql(): void {
			$ast = $this->parseHide('hide archive_log_email_idx on ArchiveLog');

			$sql = (new QuelToSQLIndexVisibility(new FakePlatformCapabilities('mysql')))->convertHideToSQL($ast);

			self::assertSame('ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` INVISIBLE', $sql);
		}

		public function testShowCompilesToVisibleOnMysql(): void {
			$ast = $this->parseShow('show archive_log_email_idx on ArchiveLog');

			$sql = (new QuelToSQLIndexVisibility(new FakePlatformCapabilities('mysql')))->convertShowToSQL($ast);

			self::assertSame('ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` VISIBLE', $sql);
		}

		public function testHideCompilesToIgnoredOnMariadb(): void {
			$ast = $this->parseHide('hide archive_log_email_idx on ArchiveLog');

			$sql = (new QuelToSQLIndexVisibility(new FakePlatformCapabilities('mariadb')))->convertHideToSQL($ast);

			self::assertSame('ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` IGNORED', $sql);
		}

		public function testShowCompilesToNotIgnoredOnMariadb(): void {
			$ast = $this->parseShow('show archive_log_email_idx on ArchiveLog');

			$sql = (new QuelToSQLIndexVisibility(new FakePlatformCapabilities('mariadb')))->convertShowToSQL($ast);

			self::assertSame('ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` NOT IGNORED', $sql);
		}

		public function testHideOnUnsupportedDialectThrows(): void {
			$ast = $this->parseHide('hide archive_log_email_idx on ArchiveLog');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('only MySQL 8.0+ and MariaDB 10.6+ support hiding an index');

			(new QuelToSQLIndexVisibility(new FakePlatformCapabilities('pgsql')))->convertHideToSQL($ast);
		}

		public function testShowOnUnsupportedDialectThrows(): void {
			$ast = $this->parseShow('show archive_log_email_idx on ArchiveLog');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('only MySQL 8.0+ and MariaDB 10.6+ support hiding an index');

			(new QuelToSQLIndexVisibility(new FakePlatformCapabilities('sqlite')))->convertShowToSQL($ast);
		}

		public function testTargetIsALiteralTableNameNotAnEntityLookup(): void {
			// No range declaration, no EntityStore involved — same as
			// create/destroy index's table name.
			$ast = $this->parseHide('hide user_username_idx on UserEntity');

			$sql = (new QuelToSQLIndexVisibility(new FakePlatformCapabilities('mysql')))->convertHideToSQL($ast);

			self::assertSame('ALTER TABLE `UserEntity` ALTER INDEX `user_username_idx` INVISIBLE', $sql);
		}

		public function testIgnoresRangeDeclarationBeforeHide(): void {
			$ast = $this->parseHide('
				range of x is UserEntity
				hide archive_log_email_idx on ArchiveLog
			');

			$sql = (new QuelToSQLIndexVisibility(new FakePlatformCapabilities('mysql')))->convertHideToSQL($ast);

			self::assertSame('ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` INVISIBLE', $sql);
		}

		public function testTrailingSemicolonIsConsumed(): void {
			$ast = $this->parseShow('show archive_log_email_idx on ArchiveLog;');

			$sql = (new QuelToSQLIndexVisibility(new FakePlatformCapabilities('mysql')))->convertShowToSQL($ast);

			self::assertSame('ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` VISIBLE', $sql);
		}
	}
