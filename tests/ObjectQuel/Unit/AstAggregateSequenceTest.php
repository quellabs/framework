<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDenseRank;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstLag;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNtile;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRank;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRowNumber;

	/**
	 * Regression coverage for the AstAggregate::$identifier nullability change and
	 * the new $order (inline `sort by`) property that back the sequence-function
	 * AST nodes (rank, dense_rank, row_number, ntile, lag, lead).
	 */
	class AstAggregateSequenceTest extends TestCase {

		private function identifierForRange(string $rangeName, string $property): AstIdentifier {
			$identifier = new AstIdentifier($property);
			$identifier->setRange(new AstRange($rangeName));
			return $identifier;
		}

		public function testNoArgumentSequenceFunctionsHaveNullIdentifier(): void {
			self::assertNull((new AstRank())->getIdentifier());
			self::assertNull((new AstDenseRank())->getIdentifier());
			self::assertNull((new AstRowNumber())->getIdentifier());
		}

		public function testNoArgumentSequenceFunctionsReturnIntegerType(): void {
			self::assertSame('integer', (new AstRank())->getReturnType());
			self::assertSame('integer', (new AstDenseRank())->getReturnType());
			self::assertSame('integer', (new AstRowNumber())->getReturnType());
			self::assertSame('integer', (new AstNtile(new AstNumber('4')))->getReturnType());
		}

		public function testLagAndNtileKeepTheirValueIdentifier(): void {
			$identifier = $this->identifierForRange('o', 'eventTime');
			$lag = new AstLag($identifier);

			self::assertSame($identifier, $lag->getIdentifier());
			self::assertSame('LAG', $lag->getType());

			$bucketCount = new AstNumber('4');
			$ntile = new AstNtile($bucketCount);

			self::assertSame($bucketCount, $ntile->getIdentifier());
		}

		public function testOrderDefaultsToNull(): void {
			self::assertNull((new AstRank())->getOrder());
		}

		public function testOrderIsStoredAndParentedToTheAggregate(): void {
			$sortAst = $this->identifierForRange('o', 'amount');
			$rank = new AstRank([['ast' => $sortAst, 'order' => 'desc']]);

			self::assertSame([['ast' => $sortAst, 'order' => 'desc']], $rank->getOrder());
			self::assertSame($rank, $sortAst->getParent());
		}

		public function testDeepCloneDuplicatesOrderAstsIndependently(): void {
			$sortAst = $this->identifierForRange('o', 'amount');
			$rank = new AstRank([['ast' => $sortAst, 'order' => 'desc']]);

			$clone = $rank->deepClone();
			$clonedOrder = $clone->getOrder();

			self::assertNotNull($clonedOrder);
			self::assertSame('desc', $clonedOrder[0]['order']);
			self::assertNotSame($sortAst, $clonedOrder[0]['ast']);
			self::assertInstanceOf(AstIdentifier::class, $clonedOrder[0]['ast']);
		}

		public function testDeepCloneOfNoArgumentSequenceFunctionKeepsNullIdentifier(): void {
			$clone = (new AstRank())->deepClone();

			self::assertNull($clone->getIdentifier());
			self::assertInstanceOf(AstRank::class, $clone);
		}

		public function testAstAnyIdentifierNeverReturnsNull(): void {
			// AstAny narrows NodeAggregate::getIdentifier() back to non-nullable —
			// ANY(...) always has a value argument, unlike the no-argument sequence
			// functions. This is a compile-time guarantee (return type), verified
			// here by confirming a normally-constructed AstAny simply hands back
			// its identifier without throwing.
			$identifier = $this->identifierForRange('o', 'id');
			$any = new AstAny($identifier);
			$any->setParent(new AstRange('dummy'));

			self::assertSame($identifier, $any->getIdentifier());
		}
	}
