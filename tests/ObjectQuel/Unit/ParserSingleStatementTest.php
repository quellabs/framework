<?php

namespace Quellabs\ObjectQuel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quellabs\ObjectQuel\EntityStore;
use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
use Quellabs\ObjectQuel\ObjectQuel\Lexer;
use Quellabs\ObjectQuel\ObjectQuel\Parser;
use Quellabs\ObjectQuel\ObjectQuel\ParserException;

class ParserSingleStatementTest extends TestCase {

	/**
	 * Creates the parser with the suite's entity store.
	 * @param string $query
	 * @return Parser
	 */
	private function parser(string $query): Parser {
		/** @var EntityStore $store */
		$store = $GLOBALS['test_em']->getEntityStore();
		return new Parser(new Lexer($query), $store);
	}

	/**
	 * Lists equivalent subtraction spellings.
	 * @return array<string, array{string}>
	 */
	public static function subtractionQueries(): array {
		return [
			'no spaces' => ['retrieve (1-2)'],
			'space before minus' => ['retrieve (1 -2)'],
			'space after minus' => ['retrieve (1- 2)'],
			'spaces around minus' => ['retrieve (1 - 2)'],
		];
	}

	/**
	 * Parses subtraction independently of whitespace around the operator.
	 * @param string $query
	 * @return void
	 */
	#[DataProvider('subtractionQueries')]
	public function testSubtractionSpacing(string $query): void {
		$ast = $this->parser($query)->parse();
		self::assertInstanceOf(AstRetrieve::class, $ast);
		self::assertInstanceOf(AstTerm::class, $ast->getValues()[0]->getExpression());
	}

	/**
	 * Rejects a second statement instead of silently returning only the first.
	 * @return void
	 */
	public function testRejectsSecondStatement(): void {
		$this->expectException(ParserException::class);
		$this->expectExceptionMessage('only one statement');
		$this->parser('retrieve (1); retrieve (2)')->parse();
	}

	/**
	 * Accepts a single statement with an optional trailing semicolon.
	 * @return void
	 */
	public function testAcceptsSingleStatementWithSemicolon(): void {
		self::assertInstanceOf(AstRetrieve::class, $this->parser('retrieve (1);')->parse());
	}
}
