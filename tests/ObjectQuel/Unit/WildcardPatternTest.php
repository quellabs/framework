<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\Helpers\WildcardPattern;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;

	class WildcardPatternTest extends TestCase {

		/**
		 * Lexes a single string literal into an AstString carrying its pattern data.
		 * @param string $source Quel string literal source
		 * @return AstString
		 */
		private function literal(string $source): AstString {
			$token = (new Lexer($source))->peek();

			return new AstString($token->getStringValue(), "'", $token->getWildcardOffsets(), $token->getCharacterClasses());
		}

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
		 * Each class becomes a single _ in the LIKE form.
		 * @return void
		 */
		public function testToSqlLikeTurnsClassIntoSingleCharacter(): void {
			$this->assertSame('Jan_%9', WildcardPattern::toSqlLike($this->literal("'Jan[A-C]*9'")));
		}

		/**
		 * T-SQL keeps the class with members listed explicitly and special members escaped.
		 * @return void
		 */
		public function testToSqlServerLikeListsClassMembers(): void {
			$this->assertSame('Jan[ABC]%\\[', WildcardPattern::toSqlServerLike($this->literal("'Jan[A-C]*\\['")));
			$this->assertSame('[\\]\\^\\[\\\\\\-]', WildcardPattern::toSqlServerLike($this->literal("'[\\]^\\[\\\\-]'")));
		}

		/**
		 * The regex body escapes metacharacters outside classes and special members inside them.
		 * @return void
		 */
		public function testToRegexBodyTranslatesWildcardsAndClasses(): void {
			$this->assertSame('a\\.b.*[xy].\\/', WildcardPattern::toRegexBody($this->literal("'a.b*[xy]?/'")));
			$this->assertSame('[\\]\\^\\-]', WildcardPattern::toRegexBody($this->literal("'[\\]^-]'")));
		}

		/**
		 * GLOB has no escape character: specials go in one-member classes and letters match both cases.
		 * @return void
		 */
		public function testToGlobEscapesSpecialsAndFoldsCase(): void {
			$this->assertSame('[jJ]*[aAbB]?[*][[]9', WildcardPattern::toGlob($this->literal("'j*[ab]?\\*\\[9'")));
		}

		/**
		 * GLOB classes put ] and - first and ^ last; a one-member class is a plain literal.
		 * @return void
		 */
		public function testToGlobOrdersSpecialClassMembers(): void {
			$this->assertSame('[]-1^]', WildcardPattern::toGlob($this->literal("'[^1\\]-]'")));
			$this->assertSame('^', WildcardPattern::toGlob($this->literal("'[^]'")));
			$this->assertSame('[*]', WildcardPattern::toGlob($this->literal("'[*]'")));
		}
	}
