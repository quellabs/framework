<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Type categories of return values, assignments and initializers, on every routine engine.
	 */
	class RoutineTypeCheckerTest extends TestCase {

		/** Engines the check must behave the same on */
		private const array ENGINES = ['pgsql', 'sqlsrv', 'mysql'];

		/**
		 * @param string $databaseType Target engine
		 * @param string $source Routine source
		 * @return list<string> Generated statements
		 */
		private function compile(string $databaseType, string $source): array {
			return (new ProcedureCompiler($GLOBALS['test_em'], new FakePlatformCapabilities($databaseType), $databaseType === 'sqlsrv' ? 'dbo' : null))->compile($source);
		}

		/**
		 * @param array<string, list<string>> $cases Test arguments after the engine, by case name
		 * @return array<string, list<string>> The cases for every engine
		 */
		private static function perEngine(array $cases): array {
			$result = [];

			foreach (self::ENGINES as $engine) {
				foreach ($cases as $name => $case) {
					$result["{$engine}: {$name}"] = [$engine, ...$case];
				}
			}

			return $result;
		}

		/**
		 * @return array<string, list<string>>
		 */
		public static function mismatches(): array {
			return self::perEngine([
				'return' => [
					'define function f () integer { return "x" }',
					"'f' returns integer, but a returned value is string.",
				],
				'parameter returned' => [
					'define function f (string s) integer { return s }',
					"'f' returns integer, but a returned value is string.",
				],
				'assignment' => [
					'define function f () void { integer n = 0 n = "x" }',
					"'n' is integer, but the assigned value is string.",
				],
				'initializer' => [
					'define function f () void { boolean b = 1 }',
					"'b' is boolean, but its initializer is numeric.",
				],
				'boolean cursor field' => [
					'define function f () void {
						string s = ""
						range of u is UserEntity
						cursor c = retrieve (u.id, u.banned)
						foreach c { s = c.banned }
					}',
					"'s' is string, but the assigned value is boolean.",
				],
				'datetime cursor field' => [
					'define function f () void {
						integer n = 0
						range of p is PostEntity
						cursor c = retrieve (p.id, p.createdAt)
						foreach c { n = c.createdAt }
					}',
					"'n' is integer, but the assigned value is datetime.",
				],
				'if condition' => [
					'define function f (int n) void { if n { n = 0 } }',
					"The condition of 'if' must be boolean, but it is numeric.",
				],
				'while condition' => [
					'define function f (string s) void { while s { s = "" } }',
					"The condition of 'while' must be boolean, but it is string.",
				],
				'procedural comparison' => [
					'define function f (int n) void { if n = "x" { n = 0 } }',
					"A comparison involving 'n' mixes numeric and string values.",
				],
				'where comparison' => [
					'define function f (string who) void { range of u is UserEntity retrieve (u.id) where u.id = who }',
					"A comparison involving 'who' mixes numeric and string values.",
				],
				'bare property comparison' => [
					'define function f (string who) void { range of u is UserEntity delete u where banned = who }',
					"A comparison involving 'who' mixes boolean and string values.",
				],
				'cursor field comparison' => [
					'define function f () void {
						range of u is UserEntity
						range of p is PostEntity
						cursor c = retrieve (u.id, u.username)
						foreach c { delete p where p.userId = c.username }
					}',
					"A comparison involving 'c.username' mixes numeric and string values.",
				],
				'in list' => [
					'define function f (string who) void { range of u is UserEntity retrieve (u.id) where who in (1, 2) }',
					"An 'in' list involving 'who' mixes string and numeric values.",
				],
				'replace value' => [
					'define function f (string who) void { range of u is UserEntity replace u (banned = who) where u.id = 1 }',
					"Column 'banned' of",
				],
				'append value' => [
					'define function f (int n) void { range of p is PostEntity append to p (title = n, content = "") }',
					"is string, but the value written to it is numeric.",
				],
				'current-row replace value' => [
					'define function f () void {
						range of u is UserEntity
						cursor c = retrieve (u.id)
						foreach c { replace c (banned = 1) }
					}',
					"is boolean, but the value written to it is numeric.",
				],
				'string increment' => [
					'define function f () void { string s = "" s++ }',
					"Arithmetic involving 's' has a string operand; '+' only works on numbers and dates.",
				],
				'string compound assignment' => [
					'define function f () void { string s = "" s -= "x" }',
					"Arithmetic involving 's' has a string operand; '-' only works on numbers and dates.",
				],
				'string plus' => [
					'define function f (string s) string { return s + "x" }',
					"Arithmetic involving 's' has a string operand; '+' only works on numbers and dates.",
				],
				'string cursor field' => [
					'define function f () void {
						integer n = 0
						range of u is UserEntity
						cursor c = retrieve (u.id, u.username)
						foreach c { n = n + c.username }
					}',
					"has a string operand; '+' only works on numbers and dates.",
				],
			]);
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $source Routine source
		 * @param string $message Expected diagnostic
		 * @return void
		 */
		#[DataProvider('mismatches')]
		public function testRejectsMismatch(string $databaseType, string $source, string $message): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage($message);
			$this->compile($databaseType, $source);
		}

		/**
		 * @return array<string, list<string>>
		 */
		public static function accepted(): array {
			return self::perEngine([
				'int to float and back' => [
					'define function f () integer { float x = 1 integer n = 1.5 n = x return n }',
				],
				'null' => [
					'define function f () integer { integer n = null return null }',
				],
				'predicate into boolean' => [
					'define function f (int n) boolean { boolean b = n > 3 and n < 10 return b }',
				],
				'string column into text' => [
					'define function f () void {
						text s = ""
						range of u is UserEntity
						cursor c = retrieve (u.id, u.username)
						foreach c { s = c.username }
					}',
				],
				'datetime column against integer and string' => [
					'define function f (int n, string d) void {
						range of p is PostEntity
						retrieve (p.id) where p.createdAt > n
						replace p (createdAt = d) where p.id = n
					}',
				],
				'query comparison without routine names' => [
					'define function f () void { range of u is UserEntity retrieve (u.id) where u.id = "x" }',
				],
				'boolean conditions' => [
					'define function f (int n) void { boolean found = n > 0 if found { n = 1 } while n > 0 { n = n - 1 } }',
				],
				'numeric increments' => [
					'define function f (int n) float { float x = 0 x++ --x x += n * 1.5 x -= n return x }',
				],
			]);
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $source Routine source
		 * @return void
		 */
		#[DataProvider('accepted')]
		public function testAccepts(string $databaseType, string $source): void {
			self::assertNotEmpty($this->compile($databaseType, $source));
		}
	}
