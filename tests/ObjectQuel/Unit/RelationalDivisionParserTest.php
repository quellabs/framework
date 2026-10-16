<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * AST shape of relational division written as `not any(required where not any(match))`.
	 */
	class RelationalDivisionParserTest extends TestCase {

		/**
		 * Parses the bundle-coverage query.
		 * @return AstRetrieve
		 */
		private function parseDivision(): AstRetrieve {
			$ast = (new Parser(new Lexer('
				range of c  is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivCustomerEntity
				range of rp is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivProductEntity
				range of o  is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivOrderEntity via c.orders
				range of oi is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivOrderItemEntity via o.items
				retrieve (c)
				where not any(rp.id where
					rp.category = "2026-bundle" and
					not any(oi.id where oi.productId = rp.id)
				)
			'), $GLOBALS['test_em']->getEntityStore()))->parse();

			self::assertInstanceOf(AstRetrieve::class, $ast);
			return $ast;
		}

		/**
		 * Asserts the expression is `<range>.<property>`.
		 * @param string $range
		 * @param string $property
		 * @param mixed $expression
		 * @return void
		 */
		private static function assertProperty(string $range, string $property, mixed $expression): void {
			self::assertInstanceOf(AstIdentifier::class, $expression);
			self::assertSame($range, $expression->getName());
			self::assertSame($property, $expression->getNext()?->getName());
		}

		public function testParsesTwoNegatedNestedAnyNodes(): void {
			$ast = $this->parseDivision();

			$anyCollector = new CollectNodes(AstAny::class);
			$notCollector = new CollectNodes(AstNot::class);
			$ast->getConditions()?->accept($anyCollector);
			$ast->getConditions()?->accept($notCollector);

			self::assertCount(2, $anyCollector->getCollectedNodes());
			self::assertCount(2, $notCollector->getCollectedNodes());
		}

		public function testOuterAnyCarriesRequiredSetFilter(): void {
			$outerNot = $this->parseDivision()->getConditions();
			self::assertInstanceOf(AstNot::class, $outerNot);

			$outerAny = $outerNot->getExpression();
			self::assertInstanceOf(AstAny::class, $outerAny);
			self::assertProperty('rp', 'id', $outerAny->getIdentifier());

			$conditions = $outerAny->getConditions();
			self::assertInstanceOf(AstBinaryOperator::class, $conditions);
			self::assertSame('AND', $conditions->getOperator());

			$filter = $conditions->getLeft();
			self::assertInstanceOf(AstExpression::class, $filter);
			self::assertSame('=', $filter->getOperator());
			self::assertProperty('rp', 'category', $filter->getLeft());
			self::assertInstanceOf(AstString::class, $filter->getRight());
			self::assertSame('2026-bundle', $filter->getRight()->getValue());
		}

		public function testInnerAnyCarriesMatchPredicate(): void {
			$outerAny = $this->parseDivision()->getConditions()->getExpression();
			$innerNot = $outerAny->getConditions()->getRight();
			self::assertInstanceOf(AstNot::class, $innerNot);

			$innerAny = $innerNot->getExpression();
			self::assertInstanceOf(AstAny::class, $innerAny);
			self::assertProperty('oi', 'id', $innerAny->getIdentifier());

			$match = $innerAny->getConditions();
			self::assertInstanceOf(AstExpression::class, $match);
			self::assertSame('=', $match->getOperator());
			self::assertProperty('oi', 'productId', $match->getLeft());
			self::assertProperty('rp', 'id', $match->getRight());
		}
	}
