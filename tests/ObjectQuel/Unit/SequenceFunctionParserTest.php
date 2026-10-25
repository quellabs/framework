<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDenseRank;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstLag;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstLead;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNtile;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRank;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRowNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Parser-level coverage for the sequence functions (rank, dense_rank,
	 * row_number, ntile, lag, lead) and the inline `sort by` extension to the
	 * existing aggregate functions (running sum/avg/count/min/max). Exercises
	 * grammar and AST shape only — SQL generation for these is not wired up yet
	 * (AggregateOptimizer does not plan them as window functions).
	 */
	class SequenceFunctionParserTest extends TestCase {

		private function parseFirstValueExpression(string $retrieveBody): mixed {
			$ast = (new Parser(new Lexer("
				range of o is PostEntity
				retrieve ({$retrieveBody})
			"), $GLOBALS['test_em']->getEntityStore()))->parse();

			self::assertInstanceOf(AstRetrieve::class, $ast);
			$values = $ast->getValues();
			self::assertNotEmpty($values);
			self::assertInstanceOf(AstAlias::class, $values[0]);
			return $values[0]->getExpression();
		}

		// -------------------------------------------------------------
		// No-argument sequence functions: rank, dense_rank, row_number
		// -------------------------------------------------------------

		public function testRankRequiresSortBy(): void {
			$expression = $this->parseFirstValueExpression('r = rank(sort by o.id desc)');

			self::assertInstanceOf(AstRank::class, $expression);
			self::assertNull($expression->getIdentifier());
			self::assertSame('desc', $expression->getOrder()[0]['order']);
		}

		public function testRankWithoutSortByThrows(): void {
			$this->expectException(ParserException::class);
			$this->parseFirstValueExpression('r = rank()');
		}

		public function testDenseRankRequiresSortBy(): void {
			$expression = $this->parseFirstValueExpression('r = dense_rank(sort by o.id)');
			self::assertInstanceOf(AstDenseRank::class, $expression);
		}

		public function testDenseRankWithoutSortByThrows(): void {
			$this->expectException(ParserException::class);
			$this->parseFirstValueExpression('r = dense_rank()');
		}

		public function testRowNumberRequiresSortBy(): void {
			$expression = $this->parseFirstValueExpression('r = row_number(sort by o.id)');
			self::assertInstanceOf(AstRowNumber::class, $expression);
		}

		public function testRowNumberWithoutSortByThrows(): void {
			$this->expectException(ParserException::class);
			$this->parseFirstValueExpression('r = row_number()');
		}

		// -------------------------------------------------------------
		// Value-bearing sequence functions: ntile, lag, lead
		// -------------------------------------------------------------

		public function testNtileRequiresSortBy(): void {
			$expression = $this->parseFirstValueExpression('r = ntile(4 sort by o.id)');

			self::assertInstanceOf(AstNtile::class, $expression);
			self::assertNotNull($expression->getIdentifier());
			self::assertNotNull($expression->getOrder());
		}

		public function testNtileWithoutSortByThrows(): void {
			$this->expectException(ParserException::class);
			$this->parseFirstValueExpression('r = ntile(4)');
		}

		public function testLagRequiresSortBy(): void {
			$expression = $this->parseFirstValueExpression('r = lag(o.title sort by o.id)');
			self::assertInstanceOf(AstLag::class, $expression);
		}

		public function testLagWithoutSortByThrows(): void {
			$this->expectException(ParserException::class);
			$this->parseFirstValueExpression('r = lag(o.title)');
		}

		public function testLeadRequiresSortBy(): void {
			$expression = $this->parseFirstValueExpression('r = lead(o.title sort by o.id)');
			self::assertInstanceOf(AstLead::class, $expression);
		}

		public function testLeadWithoutSortByThrows(): void {
			$this->expectException(ParserException::class);
			$this->parseFirstValueExpression('r = lead(o.title)');
		}

		// -------------------------------------------------------------
		// Inline `sort by` on the existing running aggregates
		// -------------------------------------------------------------

		public function testSumAcceptsAnInlineSortByForARunningTotal(): void {
			$expression = $this->parseFirstValueExpression('r = sum(o.id sort by o.id)');

			self::assertInstanceOf(AstSum::class, $expression);
			self::assertNotNull($expression->getOrder());
		}

		public function testPlainSumHasNoOrder(): void {
			$expression = $this->parseFirstValueExpression('r = sum(o.id)');

			self::assertInstanceOf(AstSum::class, $expression);
			self::assertNull($expression->getOrder());
		}

		public function testSumStillAcceptsWhereBeforeSortBy(): void {
			$expression = $this->parseFirstValueExpression("r = sum(o.id where o.title = 'x' sort by o.id)");

			self::assertInstanceOf(AstSum::class, $expression);
			self::assertNotNull($expression->getConditions());
			self::assertNotNull($expression->getOrder());
		}

		// -------------------------------------------------------------
		// Sort by is rejected where it can never be honored: ANY() and the
		// DISTINCT aggregate variants never reach the window strategy.
		// -------------------------------------------------------------

		public function testAnyRejectsAnInlineSortBy(): void {
			$this->expectException(LexerException::class);
			$this->parseFirstValueExpression('r = any(o.id sort by o.id)');
		}

		public function testCountuRejectsAnInlineSortBy(): void {
			$this->expectException(LexerException::class);
			$this->parseFirstValueExpression('r = countu(o.id sort by o.id)');
		}
	}
