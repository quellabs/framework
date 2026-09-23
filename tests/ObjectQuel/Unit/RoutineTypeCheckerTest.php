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
			return (new ProcedureCompiler($GLOBALS['test_em'], new FakePlatformCapabilities($databaseType)))->compile($source);
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
