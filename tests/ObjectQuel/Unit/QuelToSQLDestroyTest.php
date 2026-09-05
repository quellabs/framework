<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDestroy;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Dialect-level coverage for QuelToSQLDestroy. The suite's only live
	 * connection (see tests/Integration/DestroyTest) is MySQL, so this is the
	 * only place mysql/pgsql/sqlite/sqlsrv generated SQL is actually
	 * compared — in particular sqlServerUnqualifiedDrop(), the fix for the
	 * real bug found in this session: SQL Server has no native "session
	 * temp table shadows a same-named permanent one" behavior the other
	 * three engines give an unqualified `DROP TABLE` for free.
	 */
	class QuelToSQLDestroyTest extends TestCase {

		private function parse(string $query): AstDestroy {
			$ast = (new Parser(new Lexer($query)))->parse();
			self::assertInstanceOf(AstDestroy::class, $ast);
			return $ast;
		}

		/** @return string[] */
		private function compile(AstDestroy $ast, string $dialect): array {
			return (new QuelToSQLDestroy(new FakePlatformCapabilities($dialect)))->convertToSQL($ast);
		}

		public function testUnqualifiedDestroyAcrossDialects(): void {
			$ast = $this->parse('destroy Foo');

			self::assertSame(['DROP TABLE `Foo`'], $this->compile($ast, 'mysql'));
			self::assertSame(['DROP TABLE "Foo"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['DROP TABLE `Foo`'], $this->compile($ast, 'sqlite'));

			// No `temporary` given, so the real kind is unknown — SQL Server
			// must emulate the "temp shadows permanent" priority the other
			// three engines already give unqualified names natively.
			self::assertSame(
				["IF OBJECT_ID('tempdb..#Foo') IS NOT NULL DROP TABLE [#Foo] ELSE DROP TABLE [Foo]"],
				$this->compile($ast, 'sqlsrv')
			);
		}

		public function testUnqualifiedDestroyIfExistsAcrossDialects(): void {
			$ast = $this->parse('destroy Foo if exists');

			self::assertSame(['DROP TABLE IF EXISTS `Foo`'], $this->compile($ast, 'mysql'));
			self::assertSame(['DROP TABLE IF EXISTS "Foo"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['DROP TABLE IF EXISTS `Foo`'], $this->compile($ast, 'sqlite'));

			// The temp branch never needs IF EXISTS itself (existence was
			// just confirmed by the OBJECT_ID check); only the permanent
			// fallback carries it.
			self::assertSame(
				["IF OBJECT_ID('tempdb..#Foo') IS NOT NULL DROP TABLE [#Foo] ELSE DROP TABLE IF EXISTS [Foo]"],
				$this->compile($ast, 'sqlsrv')
			);
		}

		public function testTemporaryQualifierResolvesDirectlyAcrossDialects(): void {
			$ast = $this->parse('destroy temporary Foo');

			self::assertSame(['DROP TABLE `Foo`'], $this->compile($ast, 'mysql'));
			self::assertSame(['DROP TABLE "Foo"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['DROP TABLE `Foo`'], $this->compile($ast, 'sqlite'));

			// `temporary` makes the target unambiguous, so SQL Server skips
			// the OBJECT_ID probe entirely and drops the physical name directly.
			self::assertSame(['DROP TABLE [#Foo]'], $this->compile($ast, 'sqlsrv'));
		}

		public function testTemporaryQualifierWithIfExistsAcrossDialects(): void {
			$ast = $this->parse('destroy temporary Foo if exists');

			self::assertSame(['DROP TABLE IF EXISTS `Foo`'], $this->compile($ast, 'mysql'));
			self::assertSame(['DROP TABLE IF EXISTS "Foo"'], $this->compile($ast, 'pgsql'));
			self::assertSame(['DROP TABLE IF EXISTS `Foo`'], $this->compile($ast, 'sqlite'));
			self::assertSame(['DROP TABLE IF EXISTS [#Foo]'], $this->compile($ast, 'sqlsrv'));
		}

	}
