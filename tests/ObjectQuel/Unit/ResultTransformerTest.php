<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\Hydration\ResultTransformer;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;

	/**
	 * Regression coverage for sortResults() reading the wrong array key.
	 * AstRetrieve::getSort() produces 'order' (see Retrieve::parseSortExpressions),
	 * but sortResults() used to read 'direction', so it silently sorted every
	 * in-memory result set ascending regardless of "sort by ... desc".
	 */
	class ResultTransformerTest extends TestCase {

		private function identifierForRange(string $rangeName): AstIdentifier {
			$identifier = new AstIdentifier($rangeName);
			$identifier->setRange(new AstRange($rangeName));
			return $identifier;
		}

		public function testSortResultsHonorsDescendingOrder(): void {
			$results = [
				['t' => 1],
				['t' => 3],
				['t' => 2],
			];

			(new ResultTransformer())->sortResults($results, [
				['ast' => $this->identifierForRange('t'), 'order' => 'desc'],
			]);

			self::assertSame([3, 2, 1], array_column($results, 't'));
		}

		public function testSortResultsDefaultsToAscendingWhenOrderOmitted(): void {
			$results = [
				['t' => 3],
				['t' => 1],
				['t' => 2],
			];

			(new ResultTransformer())->sortResults($results, [
				['ast' => $this->identifierForRange('t')],
			]);

			self::assertSame([1, 2, 3], array_column($results, 't'));
		}
	}
