<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;

	class LexerStringWildcardTest extends TestCase {

		/**
		 * Lists string literals with their expected value and unescaped wildcard offsets.
		 * @return array<string, array{string, string, int[]}>
		 */
		public static function wildcardStrings(): array {
			return [
				'no wildcards'                 => ["'abc'", 'abc', []],
				'single-quoted wildcards'      => ["'a*b?'", 'a*b?', [1, 3]],
				'double-quoted wildcards'      => ['"a*b?"', 'a*b?', [1, 3]],
				'single-quoted escaped star'   => ["'a\\*b'", 'a*b', []],
				'double-quoted escaped star'   => ['"a\\*b"', 'a*b', []],
				'escaped question mark'        => ['"a\\?"', 'a?', []],
				'partly escaped'               => ["'\\*x*\\?y?'", '*x*?y?', [2, 5]],
				'escaped backslash then star'  => ['"a\\\\*"', 'a\\*', [2]],
				'offset after other escape'    => ['"\\n*"', "\n*", [1]]
			];
		}

		/**
		 * Escaped * and ? become literal characters; unescaped ones are recorded as wildcard offsets.
		 * @param string $source Quel string literal source
		 * @param string $expectedValue Expected token value
		 * @param int[] $expectedOffsets Expected wildcard offsets
		 * @return void
		 */
		#[DataProvider('wildcardStrings')]
		public function testRecordsUnescapedWildcardOffsets(string $source, string $expectedValue, array $expectedOffsets): void {
			$token = (new Lexer($source))->peek();

			$this->assertSame($expectedValue, $token->getStringValue());
			$this->assertSame($expectedOffsets, $token->getWildcardOffsets());
		}
	}
