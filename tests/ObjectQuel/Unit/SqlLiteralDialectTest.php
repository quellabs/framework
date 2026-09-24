<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * String literals, regex patterns and Unix timestamps per engine.
	 */
	class SqlLiteralDialectTest extends TestCase {

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function stringLiterals(): array {
			return [
				'mysql' => ['mysql', "'O''Re\\\\illy'"],
				'mariadb' => ['mariadb', "'O''Re\\\\illy'"],
				'pgsql' => ['pgsql', "'O''Re\\illy'"],
				'sqlsrv' => ['sqlsrv', "'O''Re\\illy'"],
				'sqlite' => ['sqlite', "'O''Re\\illy'"],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $expected The quoted literal
		 * @return void
		 */
		#[DataProvider('stringLiterals')]
		public function testStringLiteralIsSingleQuoted(string $databaseType, string $expected): void {
			$quoter = new SqlIdentifierQuoter(new FakePlatformCapabilities($databaseType));
			self::assertSame($expected, $quoter->quoteStringLiteral("O'Re\\illy"));
		}

		/**
		 * @return void
		 */
		public function testMysqlRegexFallbackQuotesThePattern(): void {
			$sql = $this->compileRegexRoutine(new FakePlatformCapabilities('mysql'));
			self::assertStringContainsString("IF _v_s REGEXP 'a\\\\.b''' THEN", $sql);
		}

		/**
		 * @return void
		 */
		public function testRegexpLikeQuotesPatternAndFlags(): void {
			$platform = new class('sqlsrv') extends FakePlatformCapabilities {
				/**
				 * @return bool
				 */
				public function supportsRegexpLike(): bool {
					return true;
				}
			};

			self::assertStringContainsString("IF REGEXP_LIKE(@s, 'a\\.b''', 'i')", $this->compileRegexRoutine($platform));
		}

		/**
		 * @return array<string, array{string, string, string}>
		 */
		public static function unixTimestamps(): array {
			return [
				'sqlsrv' => ['sqlsrv', "DATEDIFF_BIG(SECOND, '1970-01-01', %s)", "DATEDIFF_BIG(SECOND, '1970-01-01', SYSUTCDATETIME())"],
				'pgsql' => ['pgsql', 'EXTRACT(EPOCH FROM %s)::BIGINT', 'EXTRACT(EPOCH FROM NOW())::BIGINT'],
				'mysql' => ['mysql', 'UNIX_TIMESTAMP(%s)', 'UNIX_TIMESTAMP()'],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $column Expected column conversion template
		 * @param string $now Expected current-time expression
		 * @return void
		 */
		#[DataProvider('unixTimestamps')]
		public function testUnixTimestamp(string $databaseType, string $column, string $now): void {
			$adapter = $this->createStub(DatabaseAdapter::class);
			$adapter->method('getDatabaseType')->willReturn($databaseType);
			$platform = new PlatformCapabilities($adapter);

			self::assertSame($column, $platform->getUnixTimestampFunction());
			self::assertSame($now, $platform->getCurrentUnixTimestamp());
		}

		/**
		 * Compiles a function whose condition matches a regex containing a backslash and a quote.
		 * @param FakePlatformCapabilities $platform Target engine
		 * @return string The CREATE statement
		 */
		private function compileRegexRoutine(FakePlatformCapabilities $platform): string {
			$statements = (new ProcedureCompiler($GLOBALS['test_em'], $platform, $platform->getDatabaseType() === 'sqlsrv' ? 'dbo' : null))->compile(
				"define function f (string s) integer { if (s = /a\\.b'/i) { return 1 } return 0 }"
			);

			return end($statements);
		}
	}
