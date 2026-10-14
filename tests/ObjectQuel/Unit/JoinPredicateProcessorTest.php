<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\QueryNormalizer;
	use Quellabs\ObjectQuel\Planner\Helpers\JoinPredicateProcessor;

	/**
	 * Splitting a live range's join condition into the part kept in the JOIN and the
	 * correlation part promoted to WHERE.
	 */
	class JoinPredicateProcessorTest extends TestCase {

		/**
		 * Parses and resolves a customer/order query whose order range uses the given via condition.
		 * @param string $via
		 * @return array{customer: AstRange, order: AstRange}
		 */
		private function parseRanges(string $via): array {
			$entityStore = $GLOBALS['test_em']->getEntityStore();

			$ast = (new Parser(new Lexer("
				range of c is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivCustomerEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities\\RelDivOrderEntity via {$via}
				retrieve (c.id, o.id)
			"), $entityStore))->parse();

			self::assertInstanceOf(AstRetrieve::class, $ast);
			(new IdentifierTypeResolver($entityStore))->resolve($ast);
			(new QueryNormalizer($entityStore))->transform($ast);

			[$customer, $order] = $ast->getRanges();
			return ['customer' => $customer, 'order' => $order];
		}

		public function testPromotesConjunctReferencingOnlyCorrelationRanges(): void {
			$ranges = $this->parseRanges("o.customerId = c.id and c.name = 'full'");
			$processor = new JoinPredicateProcessor(['o' => $ranges['order']], ['c' => $ranges['customer']]);

			$promoted = $processor->gatherPromotedPredicates([$ranges['order']]);
			$updated = $processor->buildUpdatedRanges([$ranges['order']]);

			self::assertCount(1, $promoted);
			self::assertNotInstanceOf(AstBinaryOperator::class, $promoted[0]);
			self::assertNotInstanceOf(AstBinaryOperator::class, $updated[0]->getJoinProperty());
		}

		public function testKeepsOrPredicateWholeInTheJoin(): void {
			$ranges = $this->parseRanges("o.customerId = c.id or c.name = 'full'");
			$join = $ranges['order']->getJoinProperty();
			$processor = new JoinPredicateProcessor(['o' => $ranges['order']], ['c' => $ranges['customer']]);

			self::assertSame([], $processor->gatherPromotedPredicates([$ranges['order']]));

			$updated = $processor->buildUpdatedRanges([$ranges['order']]);
			self::assertSame($join, $updated[0]->getJoinProperty());
		}

		public function testPromotesOrPredicateReferencingOnlyCorrelationRanges(): void {
			$ranges = $this->parseRanges("o.customerId = c.id and (c.name = 'full' or c.id = 1)");
			$processor = new JoinPredicateProcessor(['o' => $ranges['order']], ['c' => $ranges['customer']]);

			$promoted = $processor->gatherPromotedPredicates([$ranges['order']]);

			self::assertCount(1, $promoted);
			self::assertInstanceOf(AstBinaryOperator::class, $promoted[0]);
			self::assertSame('OR', $promoted[0]->getOperator());
		}
	}
