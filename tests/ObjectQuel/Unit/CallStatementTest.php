<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLCall;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * The `name(args)` statement: parsing, the catalog query and the per-engine SQL.
	 */
	class CallStatementTest extends TestCase {

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		/**
		 * @param string $query ObjectQuel statement
		 * @return AstCall The parsed call
		 */
		private function parseCall(string $query): AstCall {
			$statement = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstCall::class, $statement);
			return $statement;
		}

		/**
		 * @param string $databaseType Target engine
		 * @return QuelToSQLCall
		 */
		private function compiler(string $databaseType): QuelToSQLCall {
			return new QuelToSQLCall($this->em()->getEntityStore(), new FakePlatformCapabilities($databaseType), $databaseType === 'sqlsrv' ? 'dbo' : null);
		}

		/**
		 * @return void
		 */
		public function testParsesACall(): void {
			$call = $this->parseCall('f(1, :p, "s");')->getCall();

			self::assertSame('f', $call->getName());
			self::assertCount(3, $call->getArguments());
		}

		/**
		 * @return void
		 */
		public function testBuiltinCantBeCalled(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("'count' is a built-in function, not a routine.");
			$this->parseCall('count(1)');
		}

		/**
		 * @return array<string, array{string, string, string, string}>
		 */
		public static function callStatements(): array {
			return [
				'pgsql' => ['pgsql', 'CALL "f"(1, :p, \'s\', true, NULL)', 'SELECT "f"(1, :p, \'s\', true, NULL) AS "f"', 'CALL "f"()'],
				'sqlsrv' => ['sqlsrv', "EXEC [dbo].[f] 1, :p, 's', 1, NULL", "SELECT [dbo].[f](1, :p, 's', 1, NULL) AS [f]", 'EXEC [dbo].[f]'],
				'mysql' => ['mysql', "CALL `f`(1, :p, 's', true, NULL)", "SELECT `f`(1, :p, 's', true, NULL) AS `f`", 'CALL `f`()'],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $procedure Expected procedure call
		 * @param string $function Expected function select
		 * @param string $noArguments Expected procedure call without arguments
		 * @return void
		 */
		#[DataProvider('callStatements')]
		public function testCallSql(string $databaseType, string $procedure, string $function, string $noArguments): void {
			$compiler = $this->compiler($databaseType);
			$parameters = ['p' => 5];

			self::assertSame($procedure, $compiler->convertToSQL($this->parseCall('f(1, :p, "s", true, null)'), true, $parameters));
			self::assertSame($function, $compiler->convertToSQL($this->parseCall('f(1, :p, "s", true, null)'), false, $parameters));
			self::assertSame($noArguments, $compiler->convertToSQL($this->parseCall('f()'), true, $parameters));
			self::assertSame(['p' => 5], $parameters);
		}

		/**
		 * @return void
		 */
		public function testNegativeLiteralIsAnArgument(): void {
			$parameters = [];
			self::assertSame('CALL `f`(-1)', $this->compiler('mysql')->convertToSQL($this->parseCall('f(-1)'), true, $parameters));
		}

		/**
		 * @param string $databaseType Target engine
		 * @return void
		 */
		#[DataProvider('engines')]
		public function testExpressionArgumentIsRejected(string $databaseType): void {
			$parameters = [];
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("The arguments of 'f()' must be literals or parameters; compute other values before the call.");
			$this->compiler($databaseType)->convertToSQL($this->parseCall('f(:p + 1)'), true, $parameters);
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function engines(): array {
			return ['pgsql' => ['pgsql'], 'sqlsrv' => ['sqlsrv'], 'mysql' => ['mysql']];
		}

		/**
		 * @return array<string, array{string, string, string}>
		 */
		public static function signatureQueries(): array {
			return [
				'pgsql' => ['pgsql', 'format_type(prorettype, NULL) AS data_type, NULL AS type_detail, NULL AS max_length FROM pg_proc WHERE proname = :name AND pg_function_is_visible(oid)', 'f'],
				'sqlsrv' => ['sqlsrv', 'FROM sys.objects o LEFT JOIN sys.parameters p ON p.object_id = o.object_id AND p.parameter_id = 0 WHERE o.object_id = OBJECT_ID(:name)', '[dbo].[f]'],
				'mysql' => ['mysql', 'DATA_TYPE AS data_type, DTD_IDENTIFIER AS type_detail, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = :name', 'f'],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $source Expected catalog lookup
		 * @param string $name Expected bound name
		 * @return void
		 */
		#[DataProvider('signatureQueries')]
		public function testSignatureQuery(string $databaseType, string $source, string $name): void {
			[$sql, $parameters] = $this->compiler($databaseType)->signatureQuery('f');

			self::assertStringContainsString(' AS is_procedure, ', $sql);
			self::assertStringContainsString($source, $sql);
			self::assertSame(['name' => $name], $parameters);
		}

		/**
		 * @return void
		 */
		public function testEngineWithoutRoutinesIsRejected(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Routines can't be called on 'sqlite'.");
			$this->compiler('sqlite')->signatureQuery('f');
		}
	}
