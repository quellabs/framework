<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\QueryNormalizer;
	use Quellabs\ObjectQuel\Planner\Helpers\JoinPredicateProcessor;

	/**
	 * Splitting live ranges' join conditions into the part kept in the JOIN and the part moved to WHERE.
	 * Customer `c` is the correlation range; order `o` and order item `oi` are live.
	 */
	class JoinPredicateProcessorTest extends TestCase {

		/**
		 * Parses and resolves customer/order/item ranges with the given via conditions.
		 * @param string $orderVia
		 * @param string $itemVia
		 * @return array<string, AstRange> Keyed by range name
		 */
		private function parseRanges(string $orderVia, string $itemVia = 'oi.orderId = o.id'): array {
			$entityStore = $GLOBALS['test_em']->getEntityStore();

			$ast = (new Parser(new Lexer("
				range of c  is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivCustomerEntity
				range of o  is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivOrderEntity via {$orderVia}
				range of oi is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivOrderItemEntity via {$itemVia}
				retrieve (c.id, o.id, oi.id)
			"), $entityStore))->parse();

			self::assertInstanceOf(AstRetrieve::class, $ast);
			(new IdentifierTypeResolver($entityStore))->resolve($ast);
			(new QueryNormalizer($entityStore))->transform($ast);

			$ranges = [];

			foreach ($ast->getRanges() as $range) {
				$ranges[$range->getName()] = $range;
			}

			return $ranges;
		}

		/**
		 * Builds a processor with `o` and `oi` live and `c` as correlation range.
		 * @param array<string, AstRange> $ranges
		 * @return JoinPredicateProcessor
		 */
		private function processor(array $ranges): JoinPredicateProcessor {
			return new JoinPredicateProcessor(['o' => $ranges['o'], 'oi' => $ranges['oi']], ['c' => $ranges['c']]);
		}

		public function testRangeJoinedOnlyToCorrelationRangesMovesWholeConditionToWhere(): void {
			$ranges = $this->parseRanges("o.customerId = c.id and c.name = 'full'");
			$join = $ranges['o']->getJoinProperty();
			$processor = $this->processor($ranges);

			self::assertSame([$join], $processor->gatherPromotedPredicates([$ranges['o']]));

			$processor->buildUpdatedRanges([$ranges['o']]);
			self::assertNull($ranges['o']->getJoinProperty());
		}

		public function testOrConditionOfRangeJoinedOnlyToCorrelationRangesMovesToWhereWhole(): void {
			$ranges = $this->parseRanges("o.customerId = c.id or c.name = 'full'");
			$join = $ranges['o']->getJoinProperty();

			self::assertSame([$join], $this->processor($ranges)->gatherPromotedPredicates([$ranges['o']]));
		}

		public function testLeftJoinedRangeKeepsWholeConditionInJoin(): void {
			$ranges = $this->parseRanges('o.customerId = c.id', "oi.orderId = o.id and c.name = 'full'");
			$join = $ranges['oi']->getJoinProperty();
			$processor = $this->processor($ranges);

			self::assertSame([], $processor->gatherPromotedPredicates([$ranges['oi']]));

			$processor->buildUpdatedRanges([$ranges['oi']]);
			self::assertSame($join, $ranges['oi']->getJoinProperty());
		}

		public function testInnerJoinedRangeMovesCorrelationConjunctToWhere(): void {
			$ranges = $this->parseRanges('o.customerId = c.id', "oi.orderId = o.id and c.name = 'full'");
			$ranges['oi']->setRequired();
			$processor = $this->processor($ranges);

			$promoted = $processor->gatherPromotedPredicates([$ranges['oi']]);
			self::assertCount(1, $promoted);
			self::assertInstanceOf(AstExpression::class, $promoted[0]);
			self::assertSame('name', $promoted[0]->getLeft()->getNext()?->getName());

			$processor->buildUpdatedRanges([$ranges['oi']]);
			$kept = $ranges['oi']->getJoinProperty();
			self::assertInstanceOf(AstExpression::class, $kept);
			self::assertSame('orderId', $kept->getLeft()->getNext()?->getName());
		}

		public function testInnerJoinedRangeKeepsOrConditionWholeInJoin(): void {
			$ranges = $this->parseRanges('o.customerId = c.id', "oi.orderId = o.id or c.name = 'full'");
			$ranges['oi']->setRequired();
			$join = $ranges['oi']->getJoinProperty();
			$processor = $this->processor($ranges);

			self::assertSame([], $processor->gatherPromotedPredicates([$ranges['oi']]));

			$processor->buildUpdatedRanges([$ranges['oi']]);
			self::assertSame($join, $ranges['oi']->getJoinProperty());
			self::assertInstanceOf(AstBinaryOperator::class, $join);
			self::assertSame('OR', $join->getOperator());
		}
	}
