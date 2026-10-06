<?php

	namespace Quellabs\Recommender\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\MemberId;
	use Quellabs\Recommender\MinHistory;
	use Quellabs\Recommender\ProductId;
	use Quellabs\Recommender\Reconciliation\BackfillRounds;
	use Quellabs\Recommender\Reconciliation\EligibilityBatchSize;
	use Quellabs\Recommender\Reconciliation\MinSimilarity;
	use Quellabs\Recommender\Reconciliation\NeighbourLimit;
	use Quellabs\Recommender\Reconciliation\SourceDepth;
	use Quellabs\Recommender\Reconciliation\ReconciliationTuning;

	/** Unit tests for ID and tuning value objects. */
	class IdentifierValueObjectsTest extends TestCase {

		/** @return void */
		public function testMemberIdAcceptsUnsigned32BitRange(): void {
			$this->assertSame(4294967295, (new MemberId(4294967295))->value);
		}

		/** @return void */
		public function testMemberIdRejectsNegative(): void {
			$this->expectException(\InvalidArgumentException::class);
			new MemberId(-1);
		}

		/** @return void */
		public function testProductIdRejectsAboveRange(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ProductId(4294967296);
		}

		/** @return void */
		public function testMinHistoryRejectsBelowOne(): void {
			$this->expectException(\InvalidArgumentException::class);
			new MinHistory(0);
		}

		/** @return void */
		public function testSourceDepthRejectsBelowFifty(): void {
			$this->expectException(\InvalidArgumentException::class);
			new SourceDepth(49);
		}

		/** @return void */
		public function testMinSimilarityRejectsOutsideOneToOneHundred(): void {
			foreach ([0, 101] as $value) {
				try {
					new MinSimilarity($value);
					$this->fail("Similarity {$value} was accepted.");
				} catch (\InvalidArgumentException) {
					$this->assertTrue(true);
				}
			}
		}

		/** @return void */
		public function testTuningStoresValuesFromValueObjects(): void {
			$tuning = new ReconciliationTuning(
				maxNeighbours: new NeighbourLimit(7),
				maxCandidateDepth: new SourceDepth(200),
				maxBackfillRounds: new BackfillRounds(2),
				maxEligibilityBatchSize: new EligibilityBatchSize(9),
			);
			$this->assertSame(7, $tuning->maxNeighbours);
			$this->assertSame(200, $tuning->maxCandidateDepth);
			$this->assertSame(2, $tuning->maxBackfillRounds);
			$this->assertSame(9, $tuning->maxEligibilityBatchSize);
		}
	}
