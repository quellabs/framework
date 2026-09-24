<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Calls inside routine bodies: `call` statements and routine calls in expressions.
	 * The expected SQL is not run against PostgreSQL or SQL Server here.
	 */
	class RoutineBodyCallTest extends TestCase {

		/**
		 * @param string $databaseType Target engine
		 * @param string $source Routine source
		 * @return string The CREATE statement
		 */
		private function compile(string $databaseType, string $source): string {
			$statements = (new ProcedureCompiler($GLOBALS['test_em'], new FakePlatformCapabilities($databaseType), $databaseType === 'sqlsrv' ? 'dbo' : null))->compile($source);
			return end($statements);
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function engines(): array {
			return ['pgsql' => ['pgsql'], 'sqlsrv' => ['sqlsrv'], 'mysql' => ['mysql'], 'mariadb' => ['mariadb']];
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function procedureCalls(): array {
			return [
				'pgsql' => ['pgsql', 'CALL "p"("_routine"."n", -1, \'s\', true, NULL, "_row_users"."id");'],
				'sqlsrv' => ['sqlsrv', "EXEC [dbo].[p] @n, -1, 's', 1, NULL, @_row_users\$id;"],
				'mysql' => ['mysql', "CALL `p`(_v_n, -1, 's', true, NULL, _row_users\$id);"],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $expected The lowered call
		 * @return void
		 */
		#[DataProvider('procedureCalls')]
		public function testCallStatement(string $databaseType, string $expected): void {
			$sql = $this->compile($databaseType, '
				define function f (int n) void {
					range of u is UserEntity
					cursor users = retrieve (u.id)
					foreach users {
						p(n, -1, "s", true, null, users.id)
					}
				}
			');

			self::assertStringContainsString($expected, $sql);
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function procedureCallsWithoutArguments(): array {
			return [
				'pgsql' => ['pgsql', 'CALL "p"();'],
				'sqlsrv' => ['sqlsrv', 'EXEC [dbo].[p];'],
				'mysql' => ['mysql', 'CALL `p`();'],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $expected The lowered call
		 * @return void
		 */
		#[DataProvider('procedureCallsWithoutArguments')]
		public function testCallStatementWithoutArguments(string $databaseType, string $expected): void {
			self::assertStringContainsString($expected, $this->compile($databaseType, 'define function f () void { p(); }'));
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function expressionCalls(): array {
			return [
				'pgsql' => ['pgsql', '"x" := "g"("_routine"."n") + 1;'],
				'sqlsrv' => ['sqlsrv', 'SET @x = [dbo].[g](@n) + 1;'],
				'mysql' => ['mysql', 'SET _v_x = `g`(_v_n) + 1;'],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $expected The lowered assignment
		 * @return void
		 */
		#[DataProvider('expressionCalls')]
		public function testCallInExpression(string $databaseType, string $expected): void {
			$sql = $this->compile($databaseType, '
				define function f (int n) integer {
					integer x = g(n) + 1
					return x
				}
			');

			self::assertStringContainsString($expected, $sql);
		}

		/**
		 * @param string $databaseType Target engine
		 * @return void
		 */
		#[DataProvider('engines')]
		public function testCallInEmbeddedQuery(string $databaseType): void {
			$sql = $this->compile($databaseType, '
				define function f (int n) void {
					range of u is UserEntity
					replace u (username = g(u.username, n)) where h(u.id) > n
				}
			');

			self::assertMatchesRegularExpression('/UPDATE .*g.*\(.*username.*\).*WHERE .*h.*\(.*id.*\) > /s', $sql);
		}

		/**
		 * @param string $databaseType Target engine
		 * @return void
		 */
		#[DataProvider('engines')]
		public function testExpressionArgumentIsRejected(string $databaseType): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("The arguments of 'p()' must be literals or variables; assign other values to a local first.");
			$this->compile($databaseType, 'define function f (int n) void { p(n + 1) }');
		}

		/**
		 * @param string $databaseType Target engine
		 * @return void
		 */
		#[DataProvider('engines')]
		public function testColumnArgumentIsRejected(string $databaseType): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("Range 'u' can only be used inside retrieve, append, replace or delete.");
			$this->compile($databaseType, 'define function f () void { range of u is UserEntity; p(u.id) }');
		}

		/**
		 * @param string $databaseType Target engine
		 * @return void
		 */
		#[DataProvider('engines')]
		public function testUndefinedArgumentIsRejected(string $databaseType): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("Undefined name 'm'.");
			$this->compile($databaseType, 'define function f () void { p(m) }');
		}

		/**
		 * @return void
		 */
		public function testCallIsAnOrdinaryName(): void {
			self::assertStringContainsString('SET _v_call = 1;', $this->compile('mysql', 'define function f () void { integer call = 1 }'));
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function statementKeywords(): array {
			return ['body keyword' => ['while'], 'top-level keyword' => ['show'], 'define' => ['DEFINE']];
		}

		/**
		 * @param string $name Routine name that starts a statement
		 * @return void
		 */
		#[DataProvider('statementKeywords')]
		public function testStatementKeywordCantNameARoutine(string $name): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("'{$name}' is a statement keyword, so a routine by that name couldn't be called as a statement.");
			$this->compile('mysql', "define function {$name} () void { abort }");
		}

		/**
		 * @return void
		 */
		public function testSqlServerFunctionCantCallAProcedure(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("'f' returns a value, so SQL Server creates it as a FUNCTION, which can't run a procedure. Make it void to call a procedure as a statement.");
			$this->compile('sqlsrv', 'define function f () integer { p() return 1 }');
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function engineNames(): array {
			return ['mysql' => ['mysql', 'MySQL'], 'mariadb' => ['mariadb', 'MariaDB']];
		}

		/**
		 * @param string $databaseType 'mysql' or 'mariadb'
		 * @param string $engineName Engine name in the message
		 * @return void
		 */
		#[DataProvider('engineNames')]
		public function testMysqlFunctionCantCallItself(string $databaseType, string $engineName): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("'fact' calls itself, but {$engineName} doesn't allow a stored function to be recursive.");
			$this->compile($databaseType, 'define function fact (int n) integer { if (n <= 1) { return 1 } return n * FACT(n - 1) }');
		}

		/**
		 * A procedure named like the function is a different routine on MySQL.
		 * @return void
		 */
		public function testMysqlFunctionCanCallAProcedureOfTheSameName(): void {
			self::assertStringContainsString('CALL `f`();', $this->compile('mysql', 'define function f () integer { f() return 1 }'));
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function recursionEngines(): array {
			return ['pgsql' => ['pgsql'], 'sqlsrv' => ['sqlsrv']];
		}

		/**
		 * @param string $databaseType Target engine
		 * @return void
		 */
		#[DataProvider('recursionEngines')]
		public function testRecursiveFunctionCompiles(string $databaseType): void {
			$sql = $this->compile($databaseType, 'define function fact (int n) integer { if (n <= 1) { return 1 } return n * fact(n - 1) }');
			self::assertStringContainsString('fact', $sql);
		}
	}
