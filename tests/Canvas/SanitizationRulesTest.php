<?php
	
	namespace Quellabs\Canvas\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Sanitization\Rules\EmailSafe;
	use Quellabs\Canvas\Sanitization\Rules\NormalizeLineEndings;
	use Quellabs\Canvas\Sanitization\Rules\PathSafe;
	use Quellabs\Canvas\Sanitization\Rules\RemoveControlChars;
	use Quellabs\Canvas\Sanitization\Rules\RemoveNullBytes;
	use Quellabs\Canvas\Sanitization\Rules\RemoveStyleAttributes;
	use Quellabs\Canvas\Sanitization\Rules\RemoveZeroWidth;
	use Quellabs\Canvas\Sanitization\Rules\ScriptSafe;
	use Quellabs\Canvas\Sanitization\Rules\SqlSafe;
	use Quellabs\Canvas\Sanitization\Rules\StripTags;
	use Quellabs\Canvas\Sanitization\Rules\Trim;
	
	/**
	 * Unit tests for all Canvas sanitization rules.
	 *
	 * Each rule is tested against:
	 * - Its intended transformation
	 * - Non-string passthrough (all rules must return non-strings unchanged)
	 * - Edge cases and boundary inputs
	 */
	class SanitizationRulesTest extends TestCase {
		
		// -------------------------------------------------------------------------
		// Trim
		// -------------------------------------------------------------------------
		
		public function testTrimRemovesLeadingAndTrailingWhitespace(): void {
			$rule = new Trim();
			$this->assertSame('hello', $rule->sanitize('  hello  '));
		}
		
		public function testTrimRemovesTabsAndNewlines(): void {
			$rule = new Trim();
			$this->assertSame('hello', $rule->sanitize("\t\nhello\n\t"));
		}
		
		public function testTrimPreservesInternalWhitespace(): void {
			$rule = new Trim();
			$this->assertSame('hello world', $rule->sanitize('  hello world  '));
		}
		
		public function testTrimReturnsEmptyStringUnchanged(): void {
			$rule = new Trim();
			$this->assertSame('', $rule->sanitize(''));
		}
		
		public function testTrimPassesThroughNonString(): void {
			$rule = new Trim();
			$this->assertSame(42, $rule->sanitize(42));
			$this->assertNull($rule->sanitize(null));
			$this->assertSame([], $rule->sanitize([]));
		}
		
		// -------------------------------------------------------------------------
		// EmailSafe
		// -------------------------------------------------------------------------
		
		public function testEmailSafeKeepsValidEmail(): void {
			$rule = new EmailSafe();
			$this->assertSame('user@example.com', $rule->sanitize('user@example.com'));
		}
		
		public function testEmailSafeRemovesDisallowedCharacters(): void {
			$rule = new EmailSafe();
			// filter_var FILTER_SANITIZE_EMAIL removes characters outside the allowed set
			$result = $rule->sanitize('user <script>@example.com');
			$this->assertStringNotContainsString('<', $result);
			$this->assertStringNotContainsString('>', $result);
		}
		
		public function testEmailSafePassesThroughNonString(): void {
			$rule = new EmailSafe();
			$this->assertSame(123, $rule->sanitize(123));
			$this->assertNull($rule->sanitize(null));
		}
		
		// -------------------------------------------------------------------------
		// NormalizeLineEndings
		// -------------------------------------------------------------------------
		
		public function testNormalizeCRLFToLF(): void {
			$rule = new NormalizeLineEndings();
			$this->assertSame("line1\nline2", $rule->sanitize("line1\r\nline2"));
		}
		
		public function testNormalizeCRToLF(): void {
			$rule = new NormalizeLineEndings();
			$this->assertSame("line1\nline2", $rule->sanitize("line1\rline2"));
		}
		
		public function testNormalizeMixedLineEndings(): void {
			$rule = new NormalizeLineEndings();
			$input = "a\r\nb\rc\nd";
			$result = $rule->sanitize($input);
			$this->assertSame("a\nb\nc\nd", $result);
		}
		
		public function testNormalizeDoesNotDoubleLF(): void {
			// CRLF → LF should not produce double LF
			$rule = new NormalizeLineEndings();
			$result = $rule->sanitize("a\r\nb");
			$this->assertSame("a\nb", $result);
		}
		
		public function testNormalizePassesThroughNonString(): void {
			$rule = new NormalizeLineEndings();
			$this->assertSame(42, $rule->sanitize(42));
		}
		
		// -------------------------------------------------------------------------
		// PathSafe
		// -------------------------------------------------------------------------
		
		public function testPathSafeRemovesUnixTraversal(): void {
			$rule = new PathSafe();
			$this->assertSame('etc/passwd', $rule->sanitize('../etc/passwd'));
		}
		
		public function testPathSafeRemovesWindowsTraversal(): void {
			$rule = new PathSafe();
			// ..\ is in the replacement list
			$result = $rule->sanitize('..\\windows\\system32');
			$this->assertStringNotContainsString('..\\', $result);
		}
		
		public function testPathSafeRemovesNullBytes(): void {
			$rule = new PathSafe();
			// The rule removes the null byte character itself; surrounding text is preserved.
			// "file.php\0.jpg" becomes "file.php.jpg" — the null byte is gone but ".jpg" stays.
			$this->assertSame('file.php.jpg', $rule->sanitize("file.php\0.jpg"));
		}
		
		public function testPathSafeRemovesStandaloneNullByte(): void {
			$rule = new PathSafe();
			$this->assertSame('filephp', $rule->sanitize("file\0php"));
		}
		
		public function testPathSafePreservesNormalPath(): void {
			$rule = new PathSafe();
			$this->assertSame('uploads/image.jpg', $rule->sanitize('uploads/image.jpg'));
		}
		
		public function testPathSafePassesThroughNonString(): void {
			$rule = new PathSafe();
			$this->assertNull($rule->sanitize(null));
		}
		
		// -------------------------------------------------------------------------
		// RemoveControlChars
		// -------------------------------------------------------------------------
		
		public function testRemoveControlCharsRemovesNullByte(): void {
			$rule = new RemoveControlChars();
			$this->assertSame('ab', $rule->sanitize("a\x00b"));
		}
		
		public function testRemoveControlCharsRemovesBackspace(): void {
			$rule = new RemoveControlChars();
			$this->assertSame('ab', $rule->sanitize("a\x08b"));
		}
		
		public function testRemoveControlCharsRemovesDeleteChar(): void {
			$rule = new RemoveControlChars();
			$this->assertSame('ab', $rule->sanitize("a\x7Fb"));
		}
		
		public function testRemoveControlCharsPreservesTab(): void {
			$rule = new RemoveControlChars();
			$this->assertSame("a\tb", $rule->sanitize("a\tb"));
		}
		
		public function testRemoveControlCharsPreservesLineFeed(): void {
			$rule = new RemoveControlChars();
			$this->assertSame("a\nb", $rule->sanitize("a\nb"));
		}
		
		public function testRemoveControlCharsPreservesCarriageReturn(): void {
			$rule = new RemoveControlChars();
			$this->assertSame("a\rb", $rule->sanitize("a\rb"));
		}
		
		public function testRemoveControlCharsPassesThroughNonString(): void {
			$rule = new RemoveControlChars();
			$this->assertSame(3.14, $rule->sanitize(3.14));
		}
		
		// -------------------------------------------------------------------------
		// RemoveNullBytes
		// -------------------------------------------------------------------------
		
		public function testRemoveNullBytesStripsNullByte(): void {
			$rule = new RemoveNullBytes();
			$this->assertSame('hello', $rule->sanitize("hel\0lo"));
		}
		
		public function testRemoveNullBytesStripsMultipleNullBytes(): void {
			$rule = new RemoveNullBytes();
			$this->assertSame('abc', $rule->sanitize("a\0b\0c"));
		}
		
		public function testRemoveNullBytesLeavesNormalStringUnchanged(): void {
			$rule = new RemoveNullBytes();
			$this->assertSame('normal string', $rule->sanitize('normal string'));
		}
		
		public function testRemoveNullBytesPassesThroughNonString(): void {
			$rule = new RemoveNullBytes();
			$this->assertTrue($rule->sanitize(true));
		}
		
		// -------------------------------------------------------------------------
		// RemoveStyleAttributes
		// -------------------------------------------------------------------------
		
		public function testRemoveStyleAttributesRemovesDoubleQuotedStyle(): void {
			$rule = new RemoveStyleAttributes();
			$input = '<div style="color: red;">text</div>';
			$result = $rule->sanitize($input);
			$this->assertStringNotContainsString('style=', $result);
			$this->assertStringContainsString('<div>', $result);
		}
		
		public function testRemoveStyleAttributesRemovesSingleQuotedStyle(): void {
			$rule = new RemoveStyleAttributes();
			$input = "<p style='font-size:12px'>text</p>";
			$result = $rule->sanitize($input);
			$this->assertStringNotContainsString('style=', $result);
		}
		
		public function testRemoveStyleAttributesIsCaseInsensitive(): void {
			$rule = new RemoveStyleAttributes();
			$result = $rule->sanitize('<span STYLE="display:none">x</span>');
			$this->assertStringNotContainsString('STYLE=', $result);
		}
		
		public function testRemoveStyleAttributesDoesNotTouchNonHtml(): void {
			$rule = new RemoveStyleAttributes();
			$this->assertSame('plain text', $rule->sanitize('plain text'));
		}
		
		public function testRemoveStyleAttributesPreservesOtherAttributes(): void {
			$rule = new RemoveStyleAttributes();
			$input = '<a href="/path" style="color:blue" class="link">x</a>';
			$result = $rule->sanitize($input);
			$this->assertStringContainsString('href="/path"', $result);
			$this->assertStringContainsString('class="link"', $result);
			$this->assertStringNotContainsString('style=', $result);
		}
		
		public function testRemoveStyleAttributesPassesThroughNonString(): void {
			$rule = new RemoveStyleAttributes();
			$this->assertSame([], $rule->sanitize([]));
		}
		
		// -------------------------------------------------------------------------
		// RemoveZeroWidth
		// -------------------------------------------------------------------------
		
		public function testRemoveZeroWidthStripsZeroWidthSpace(): void {
			$rule = new RemoveZeroWidth();
			// U+200B Zero Width Space
			$input = "hello\u{200B}world";
			$this->assertSame('helloworld', $rule->sanitize($input));
		}
		
		public function testRemoveZeroWidthStripsBOM(): void {
			$rule = new RemoveZeroWidth();
			$input = "\u{FEFF}content";
			$this->assertSame('content', $rule->sanitize($input));
		}
		
		public function testRemoveZeroWidthStripsMultipleInvisibleChars(): void {
			$rule = new RemoveZeroWidth();
			$input = "a\u{200B}\u{200C}\u{200D}b";
			$this->assertSame('ab', $rule->sanitize($input));
		}
		
		public function testRemoveZeroWidthLeavesNormalTextUnchanged(): void {
			$rule = new RemoveZeroWidth();
			$this->assertSame('Hello World', $rule->sanitize('Hello World'));
		}
		
		public function testRemoveZeroWidthPassesThroughNonString(): void {
			$rule = new RemoveZeroWidth();
			$this->assertSame(0, $rule->sanitize(0));
		}
		
		// -------------------------------------------------------------------------
		// ScriptSafe
		// -------------------------------------------------------------------------
		
		public function testScriptSafeRemovesScriptTag(): void {
			$rule = new ScriptSafe();
			$input = '<p>Hello</p><script>alert("xss")</script>';
			$result = $rule->sanitize($input);
			$this->assertStringNotContainsString('<script', $result);
			$this->assertStringNotContainsString('alert', $result);
		}
		
		public function testScriptSafeRemovesOnClickEventHandler(): void {
			$rule = new ScriptSafe();
			$input = '<button onclick="evil()">Click</button>';
			$result = $rule->sanitize($input);
			$this->assertStringNotContainsString('onclick', $result);
		}
		
		public function testScriptSafeRemovesJavascriptProtocol(): void {
			$rule = new ScriptSafe();
			$input = '<a href="javascript:alert(1)">link</a>';
			$result = $rule->sanitize($input);
			$this->assertStringNotContainsString('javascript:', $result);
		}
		
		public function testScriptSafeIsCaseInsensitiveOnScriptTag(): void {
			$rule = new ScriptSafe();
			$result = $rule->sanitize('<SCRIPT>evil()</SCRIPT>');
			$this->assertStringNotContainsString('SCRIPT', $result);
		}
		
		public function testScriptSafePreservesNormalHtml(): void {
			$rule = new ScriptSafe();
			$input = '<p class="intro">Hello <strong>World</strong></p>';
			$result = $rule->sanitize($input);
			$this->assertStringContainsString('<p class="intro">', $result);
			$this->assertStringContainsString('<strong>', $result);
		}
		
		public function testScriptSafePassesThroughNonString(): void {
			$rule = new ScriptSafe();
			$this->assertSame(99, $rule->sanitize(99));
		}
		
		// -------------------------------------------------------------------------
		// SqlSafe
		// -------------------------------------------------------------------------
		
		public function testSqlSafeRemovesSingleLineComment(): void {
			$rule = new SqlSafe();
			$result = $rule->sanitize("value -- drop table");
			$this->assertStringNotContainsString('--', $result);
		}
		
		public function testSqlSafeRemovesMultiLineComment(): void {
			$rule = new SqlSafe();
			$result = $rule->sanitize("value /* comment */");
			$this->assertStringNotContainsString('/*', $result);
		}
		
		public function testSqlSafeRemovesTrailingSemicolon(): void {
			$rule = new SqlSafe();
			$result = $rule->sanitize("value;");
			$this->assertStringNotContainsString(';', $result);
		}
		
		public function testSqlSafeRemovesSelectKeyword(): void {
			$rule = new SqlSafe();
			$result = $rule->sanitize("1 UNION SELECT * FROM users");
			$this->assertStringNotContainsString('SELECT ', $result);
		}
		
		public function testSqlSafeRemovesDropKeyword(): void {
			$rule = new SqlSafe();
			$result = $rule->sanitize("'; DROP TABLE users; --");
			$this->assertStringNotContainsString('DROP ', $result);
		}
		
		public function testSqlSafePreservesNormalText(): void {
			$rule = new SqlSafe();
			$this->assertSame('Hello World', $rule->sanitize('Hello World'));
		}
		
		public function testSqlSafePassesThroughNonString(): void {
			$rule = new SqlSafe();
			$this->assertSame(false, $rule->sanitize(false));
		}
		
		// -------------------------------------------------------------------------
		// StripTags
		// -------------------------------------------------------------------------
		
		public function testStripTagsRemovesAllTagsByDefault(): void {
			$rule = new StripTags();
			$result = $rule->sanitize('<p>Hello <strong>World</strong></p>');
			$this->assertSame('Hello World', $result);
		}
		
		public function testStripTagsPreservesAllowedTags(): void {
			$rule = new StripTags(['p', 'strong']);
			$result = $rule->sanitize('<p>Hello <strong>World</strong></p>');
			$this->assertStringContainsString('<p>', $result);
			$this->assertStringContainsString('<strong>', $result);
		}
		
		public function testStripTagsRemovesDisallowedTagsWhenAllowListSet(): void {
			$rule = new StripTags(['p']);
			$result = $rule->sanitize('<p>Hello <script>evil()</script></p>');
			$this->assertStringNotContainsString('<script>', $result);
			$this->assertStringContainsString('<p>', $result);
		}
		
		public function testStripTagsPassesThroughEmptyString(): void {
			$rule = new StripTags();
			$this->assertSame('', $rule->sanitize(''));
		}
		
		public function testStripTagsPassesThroughNonString(): void {
			$rule = new StripTags();
			$this->assertSame(42, $rule->sanitize(42));
			$this->assertNull($rule->sanitize(null));
		}
	}