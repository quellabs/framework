<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	class CharacterClassPatternParserTest extends TestCase {

		/**
		 * Parses a query against the test entity store.
		 * @param string $query ObjectQuel query
		 * @return AstInterface
		 */
		private function parse(string $query): AstInterface {
			return (new Parser(new Lexer($query), $GLOBALS['test_em']->getEntityStore()))->parse();
		}

		/**
		 * Lists comparisons whose string is used as a pattern.
		 * @return array<string, array{string}>
		 */
		public static function patternComparisons(): array {
			return [
				'equals'     => ["u.username = 'a[bc'"],
				'not equals' => ["u.username <> 'a[]'"]
			];
		}

		/**
		 * A malformed class in a string compared with = or <> is a parse error.
		 * @param string $condition Where condition
		 * @return void
		 */
		#[DataProvider('patternComparisons')]
		public function testMalformedClassInPatternIsRejected(string $condition): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage('Escape a literal [ or ] with a backslash');

			$this->parse("range of u is App\\Entities\\UserEntity retrieve (u.id) where {$condition}");
		}

		/**
		 * A string that is not used as a pattern may contain an unclosed [.
		 * @return void
		 */
		public function testMalformedClassOutsidePatternIsAccepted(): void {
			$ast = $this->parse("range of u is App\\Entities\\UserEntity retrieve (u.id) where u.username > 'a['");

			$this->assertInstanceOf(AstInterface::class, $ast);
		}
	}
