<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyRoutine;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDestroyRoutine;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * `destroy function name [if exists]`: parsing and per-engine SQL.
	 */
	class QuelToSQLDestroyRoutineTest extends TestCase {

		/**
		 * @param string $query ObjectQuel statement
		 * @return AstInterface|null Parsed statement
		 */
		private function parse(string $query): ?AstInterface {
			return (new Parser(new Lexer($query), $GLOBALS['test_em']->getEntityStore()))->parse();
		}

		/**
		 * @return void
		 */
		public function testParsesRoutineForm(): void {
			$statement = $this->parse('destroy function count_users if exists');

			self::assertInstanceOf(AstDestroyRoutine::class, $statement);
			self::assertSame('count_users', $statement->getName());
			self::assertTrue($statement->isIfExists());
		}

		/**
		 * A table or index named `function` keeps parsing as before.
		 * @return void
		 */
		public function testFunctionAsTableOrIndexName(): void {
			self::assertInstanceOf(AstDestroy::class, $this->parse('destroy function'));
			self::assertInstanceOf(AstDestroy::class, $this->parse('destroy function if exists'));
			self::assertInstanceOf(AstDestroyIndex::class, $this->parse('destroy function on posts'));
		}

		/**
		 * @return array<string, array{string, bool, list<string>}>
		 */
		public static function dropStatements(): array {
			return [
				'pgsql' => ['pgsql', false, ['DROP ROUTINE "f"']],
				'pgsql if exists' => ['pgsql', true, ['DROP ROUTINE IF EXISTS "f"']],
				'sqlsrv' => ['sqlsrv', false, ["IF OBJECT_ID(N'f', N'P') IS NOT NULL DROP PROCEDURE [f] ELSE DROP FUNCTION [f]"]],
				'sqlsrv if exists' => ['sqlsrv', true, ["IF OBJECT_ID(N'f', N'P') IS NOT NULL DROP PROCEDURE [f] ELSE DROP FUNCTION IF EXISTS [f]"]],
				'mysql' => ['mysql', false, ['DROP FUNCTION IF EXISTS `f`', 'DROP PROCEDURE IF EXISTS `f`']],
				'mariadb if exists' => ['mariadb', true, ['DROP FUNCTION IF EXISTS `f`', 'DROP PROCEDURE IF EXISTS `f`']],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param bool $ifExists Whether `if exists` is given
		 * @param list<string> $expected Expected statements
		 * @return void
		 */
		#[DataProvider('dropStatements')]
		public function testDropStatements(string $databaseType, bool $ifExists, array $expected): void {
			$compiler = new QuelToSQLDestroyRoutine(new FakePlatformCapabilities($databaseType));
			self::assertSame($expected, $compiler->convertToSQL(new AstDestroyRoutine('f', $ifExists)));
		}

		/**
		 * @return void
		 */
		public function testSqliteIsUnsupported(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Routines can't be destroyed on 'sqlite'.");
			(new QuelToSQLDestroyRoutine(new FakePlatformCapabilities('sqlite')))->convertToSQL(new AstDestroyRoutine('f'));
		}
	}
