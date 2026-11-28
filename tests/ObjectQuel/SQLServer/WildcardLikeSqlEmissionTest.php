<?php

	namespace Quellabs\ObjectQuel\Tests\SQLServer;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;
	use Quellabs\ObjectQuel\Tests\Support\RetrieveSqlCompiler;

	/**
	 * T-SQL emission for wildcard comparisons; T-SQL LIKE treats [ as a class opener, so a literal [ must be escaped.
	 */
	class WildcardLikeSqlEmissionTest extends TestCase {

		/**
		 * Compiles a retrieve query to T-SQL.
		 * @param string $query ObjectQuel query
		 * @return string
		 */
		private function compile(string $query): string {
			return (new RetrieveSqlCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('sqlsrv')))->compile($query);
		}

		/**
		 * A literal [ in a wildcard pattern is escaped in the LIKE pattern.
		 * @return void
		 */
		public function testLiteralOpeningBracketIsEscaped(): void {
			$sql = $this->compile("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where u.username = 'a\\[b]*'
			");

			self::assertStringContainsString("LIKE 'a\\[b]%' ESCAPE '\\'", $sql);
		}

		/**
		 * A character class stays native in T-SQL LIKE, with its members listed and special ones escaped.
		 * @return void
		 */
		public function testCharacterClassUsesNativeLike(): void {
			$sql = $this->compile("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where u.username = 'Jan[A-C]*' and u.username <> 'a[\\]^-]b'
			");

			self::assertStringContainsString("[u].[username] LIKE 'Jan[ABC]%' ESCAPE '\\'", $sql);
			self::assertStringContainsString("[u].[username] NOT LIKE 'a[\\]\\^\\-]b' ESCAPE '\\'", $sql);
		}
	}
