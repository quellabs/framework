<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\CharacterClass;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;

	class LexerStringCharacterClassTest extends TestCase {

		/**
		 * Lists string literals with their expected value, classes as [start, end, characters] and wildcard offsets.
		 * @return array<string, array{string, string, list<array{int, int, list<string>}>, int[]}>
		 */
		public static function classStrings(): array {
			return [
				'single members'           => ["'J[ab]n'", 'J[ab]n', [[1, 5, ['a', 'b']]], []],
				'range'                    => ["'[A-C]'", '[A-C]', [[0, 5, ['A', 'B', 'C']]], []],
				'digit range and member'   => ["'[0-2x]'", '[0-2x]', [[0, 6, ['0', '1', '2', 'x']]], []],
				'duplicates removed'       => ["'[aa-b]'", '[aa-b]', [[0, 6, ['a', 'b']]], []],
				'leading dash literal'     => ["'[-a]'", '[-a]', [[0, 4, ['-', 'a']]], []],
				'trailing dash literal'    => ["'[a-]'", '[a-]', [[0, 4, ['a', '-']]], []],
				'caret is a member'        => ["'[^a]'", '[^a]', [[0, 4, ['^', 'a']]], []],
				'wildcards inside literal' => ["'[*?]'", '[*?]', [[0, 4, ['*', '?']]], []],
				'escaped bracket member'   => ["'[\\]a]'", '[]a]', [[0, 4, [']', 'a']]], []],
				'escaped open bracket'     => ["'a\\[b]'", 'a[b]', [], []],
				'double-quoted escapes'    => ['"\\[x\\]"', '[x]', [], []],
				'multibyte member'         => ["'[éa]'", '[éa]', [[0, 5, ['é', 'a']]], []],
				'classes and wildcards'    => ["'Jan[A-C]*9?'", 'Jan[A-C]*9?', [[3, 8, ['A', 'B', 'C']]], [8, 10]],
				'two classes'              => ["'[a][b]'", '[a][b]', [[0, 3, ['a']], [3, 6, ['b']]], []]
			];
		}

		/**
		 * Unescaped [...] is recorded as a class with ranges expanded; the value keeps the brackets as written.
		 * @param string $source Quel string literal source
		 * @param string $expectedValue Expected token value
		 * @param list<array{int, int, list<string>}> $expectedClasses Expected classes
		 * @param int[] $expectedOffsets Expected wildcard offsets
		 * @return void
		 */
		#[DataProvider('classStrings')]
		public function testRecordsCharacterClasses(string $source, string $expectedValue, array $expectedClasses, array $expectedOffsets): void {
			$token = (new Lexer($source))->peek();

			$classes = array_map(
				fn(CharacterClass $class): array => [$class->getStart(), $class->getEnd(), $class->getCharacters()],
				$token->getCharacterClasses()
			);

			$this->assertSame($expectedValue, $token->getStringValue());
			$this->assertSame($expectedClasses, $classes);
			$this->assertSame($expectedOffsets, $token->getWildcardOffsets());
			$this->assertNull($token->getPatternError());
		}

		/**
		 * Lists malformed class literals with a fragment of the expected pattern error.
		 * @return array<string, array{string, string}>
		 */
		public static function invalidClassStrings(): array {
			return [
				'unterminated'      => ["'a[bc'", 'Unterminated character class'],
				'empty'             => ["'a[]'", 'Empty character class'],
				'descending range'  => ["'[c-a]'", "Invalid range 'c-a'"],
				'mixed group range' => ["'[a-Z]'", "Invalid range 'a-Z'"],
				'symbol range'      => ["'[!-/]'", "Invalid range '!-/'"],
				'non-ascii range'   => ["'[à-ü]'", "Invalid range 'à-ü'"],
				'dash after range'  => ["'[a-c-e]'", "Ambiguous '-'"]
			];
		}

		/**
		 * Malformed classes are recorded as a pattern error; the value is still the plain text.
		 * @param string $source Quel string literal source
		 * @param string $expectedError Expected fragment of the error
		 * @return void
		 */
		#[DataProvider('invalidClassStrings')]
		public function testRecordsPatternErrorForMalformedClass(string $source, string $expectedError): void {
			$token = (new Lexer($source))->peek();

			$this->assertStringContainsString($expectedError, (string)$token->getPatternError());
			$this->assertSame(substr($source, 1, -1), $token->getStringValue());
		}
	}
