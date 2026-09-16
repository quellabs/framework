<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	use Quellabs\ObjectQuel\Sculpt\Helpers\QuelLiteralEscaper;

	/**
	 * Unit tests for QuelLiteralEscaper — see Phase 5 of
	 * objectquel-migrations-implementation-plan.md, "Escaping". The
	 * strongest possible test here is a real round trip through the Lexer:
	 * escape a raw value, splice it into a single-quoted Quel literal, lex
	 * that literal back, and assert the result equals the original raw
	 * value exactly.
	 */
	class QuelLiteralEscaperTest extends TestCase {

		/**
		 * Lexes a single-quoted Quel string literal (e.g. "'O\\'Brien'")
		 * and returns its unescaped value, exactly as the real parser would
		 * see it.
		 */
		private function lexStringLiteral(string $quelLiteral): string {
			return (new Lexer($quelLiteral))->match(Token::String)->getStringValue();
		}

		public function testPlainValueRoundTrips(): void {
			$raw = 'active';
			$escaped = QuelLiteralEscaper::escape($raw);

			$this->assertSame($raw, $this->lexStringLiteral("'{$escaped}'"));
		}

		public function testValueContainingASingleQuoteRoundTrips(): void {
			$raw = "O'Brien";
			$escaped = QuelLiteralEscaper::escape($raw);

			$this->assertSame("O\\'Brien", $escaped);
			$this->assertSame($raw, $this->lexStringLiteral("'{$escaped}'"));
		}

		public function testValueContainingABackslashRoundTrips(): void {
			$raw = 'C:\\path\\to\\file';
			$escaped = QuelLiteralEscaper::escape($raw);

			$this->assertSame('C:\\\\path\\\\to\\\\file', $escaped);
			$this->assertSame($raw, $this->lexStringLiteral("'{$escaped}'"));
		}

		public function testValueContainingBothABackslashAndAQuoteRoundTrips(): void {
			$raw = "back\\slash's quote";
			$escaped = QuelLiteralEscaper::escape($raw);

			$this->assertSame($raw, $this->lexStringLiteral("'{$escaped}'"));
		}

		public function testValueThatIsJustABackslashRoundTrips(): void {
			$raw = '\\';
			$escaped = QuelLiteralEscaper::escape($raw);

			$this->assertSame($raw, $this->lexStringLiteral("'{$escaped}'"));
		}

		public function testValueThatIsJustAQuoteRoundTrips(): void {
			$raw = "'";
			$escaped = QuelLiteralEscaper::escape($raw);

			$this->assertSame($raw, $this->lexStringLiteral("'{$escaped}'"));
		}

		public function testEmptyValueRoundTrips(): void {
			$this->assertSame('', $this->lexStringLiteral("'" . QuelLiteralEscaper::escape('') . "'"));
		}

		/**
		 * Backslash must be escaped before quote — escaping the quote first
		 * would introduce a fresh backslash the (already-finished)
		 * backslash pass would never see.
		 */
		public function testBackslashIsEscapedBeforeQuoteNotAfter(): void {
			$raw = "\\'";
			$this->assertSame('\\\\\\\'', QuelLiteralEscaper::escape($raw));
			$this->assertSame($raw, $this->lexStringLiteral("'" . QuelLiteralEscaper::escape($raw) . "'"));
		}
	}
