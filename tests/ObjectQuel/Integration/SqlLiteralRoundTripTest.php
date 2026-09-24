<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * String literals and regex patterns with quotes and backslashes, against the suite's MySQL connection.
	 */
	class SqlLiteralRoundTripTest extends TestCase {

		/** @var list<string> Routines a test defined, destroyed afterwards */
		private array $routines = [];

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		/**
		 * Defines a routine and records it for cleanup.
		 * @param string $suffix Name suffix
		 * @param string $definition Everything after the name: parameters, return type and body
		 * @return string The routine's name
		 */
		private function define(string $suffix, string $definition): string {
			$name = 'literal_' . getmypid() . "_{$suffix}";
			$this->routines[] = $name;
			self::em()->executeQuery("define function {$name} {$definition}");
			return $name;
		}

		/**
		 * @param string $name Routine name
		 * @param string $arguments QUEL argument list
		 * @return mixed The routine's result
		 */
		private function call(string $name, string $arguments = ''): mixed {
			$result = self::em()->executeQuery("call {$name}({$arguments})");
			self::assertNotNull($result);
			return iterator_to_array($result)[0][$name];
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			foreach ($this->routines as $name) {
				self::em()->executeQuery("destroy function {$name} if exists");
			}
		}

		/**
		 * @return void
		 */
		public function testStringWithQuoteAndBackslashRoundTrips(): void {
			$name = $this->define('text', '() string { return "O\'Re\\\\illy \\"q\\"" }');
			self::assertSame('O\'Re\\illy "q"', $this->call($name));
		}

		/**
		 * An escaped dot in the pattern matches only a literal dot.
		 * @return void
		 */
		public function testRegexBackslashReachesTheEngine(): void {
			$name = $this->define('match', '(string s) integer { if (s = /^a\\.b$/) { return 1 } return 0 }');

			self::assertSame(1, $this->call($name, '"a.b"'));
			self::assertSame(0, $this->call($name, '"axb"'));
		}
	}
