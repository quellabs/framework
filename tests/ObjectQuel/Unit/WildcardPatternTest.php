<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\Helpers\WildcardPattern;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;

	class WildcardPatternTest extends TestCase {

		/**
		 * Wildcards map to % and _; literal %, _ and backslash are escaped; a literal * stays as-is.
		 * @return void
		 */
		public function testToSqlLikeConvertsOnlyWildcardOffsets(): void {
			$string = new AstString('5%_\\*x*?', "'", [6, 7]);

			$this->assertSame('5\\%\\_\\\\*x%_', WildcardPattern::toSqlLike($string));
		}

		/**
		 * A literal [ is escaped so T-SQL LIKE does not read it as a character class; ] stays as-is.
		 * @return void
		 */
		public function testToSqlLikeEscapesOpeningBracket(): void {
			$string = new AstString('a[b]*', "'", [4]);

			$this->assertSame('a\\[b]%', WildcardPattern::toSqlLike($string));
		}

		/**
		 * Wildcards stay as-is; literal *, ?, [, ] and backslash are escaped.
		 * @return void
		 */
		public function testToFnmatchEscapesLiteralSpecialCharacters(): void {
			$string = new AstString('[*]?\\*', "'", [5]);

			$this->assertSame('\\[\\*\\]\\?\\\\*', WildcardPattern::toFnmatch($string));
		}
	}
