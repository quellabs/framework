<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLCall;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * The `name(args)` statement: parsing and the per-engine SQL.
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
		 * @return array<string, array{string, string, string}>
		 */
		public static function expressionArguments(): array {
			return [
				'pgsql' => ['pgsql', 'CALL "f"(:p + 1, 1 > 0 AND :p < 3)', 'SELECT "f"(:p + 1, 1 > 0 AND :p < 3) AS "f"'],
				'mysql' => ['mysql', 'CALL `f`(:p + 1, 1 > 0 AND :p < 3)', 'SELECT `f`(:p + 1, 1 > 0 AND :p < 3) AS `f`'],
			];
		}

		/**
		 * Engines whose procedure call takes expressions compile them inline.
		 * @param string $databaseType Target engine
		 * @param string $procedure Expected procedure call
		 * @param string $function Expected function select
		 * @return void
		 */
		#[DataProvider('expressionArguments')]
		public function testExpressionArgumentsCompileInline(string $databaseType, string $procedure, string $function): void {
			$compiler = $this->compiler($databaseType);
			$call = $this->parseCall('f(:p + 1, 1 > 0 and :p < 3)');
			$parameters = ['p' => 5];

			self::assertFalse($compiler->requiresEvaluatedArguments($call, true));
			self::assertSame($procedure, $compiler->convertToSQL($call, true, $parameters));
			self::assertSame($function, $compiler->convertToSQL($call, false, $parameters));
		}

		/**
		 * SQL Server selects a function's expression arguments inline, but evaluates a procedure's before the EXEC.
		 * @return void
		 */
		public function testSqlServerProcedureEvaluatesExpressionArgumentsFirst(): void {
			$compiler = $this->compiler('sqlsrv');
			$call = $this->parseCall('f(:p + 1, 1 > 0 and :p < 3, "s")');
			$parameters = ['p' => 5];

			self::assertFalse($compiler->requiresEvaluatedArguments($call, false));
			self::assertSame('SELECT [dbo].[f](:p + 1, CASE WHEN 1 > 0 AND :p < 3 THEN 1 WHEN NOT (1 > 0 AND :p < 3) THEN 0 END, \'s\') AS [f]', $compiler->convertToSQL($call, false, $parameters));

			self::assertTrue($compiler->requiresEvaluatedArguments($call, true));
			self::assertSame('SELECT :p + 1 AS [_call_arg0], CASE WHEN 1 > 0 AND :p < 3 THEN 1 WHEN NOT (1 > 0 AND :p < 3) THEN 0 END AS [_call_arg1], \'s\' AS [_call_arg2]', $compiler->argumentsQuery($call, $parameters));

			$callParameters = [];
			self::assertSame('EXEC [dbo].[f] :_call_arg0, :_call_arg1, :_call_arg2', $compiler->convertToSQL($call, true, $callParameters, [6, 1, 's']));
			self::assertSame(['_call_arg0' => 6, '_call_arg1' => 1, '_call_arg2' => 's'], $callParameters);
		}

		/**
		 * @return void
		 */
		public function testSqlServerProcedureTakesLiteralsAndParametersDirectly(): void {
			self::assertFalse($this->compiler('sqlsrv')->requiresEvaluatedArguments($this->parseCall('f(1, :p, "s", true, null, -1)'), true));
		}

	}
