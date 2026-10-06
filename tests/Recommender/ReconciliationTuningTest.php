<?php

	namespace Quellabs\Recommender\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\Reconciliation\ReconciliationTuning;

	/** Unit tests for ReconciliationTuning validation. */
	class ReconciliationTuningTest extends TestCase {

		/** @return void */
		public function testStoresOverrides(): void {
			$tuning = new ReconciliationTuning(
				maxNeighbours: 7,
				maxCandidateDepth: 200,
				maxBackfillRounds: 2,
				maxEligibilityBatchSize: 9,
			);

			$this->assertSame(7, $tuning->maxNeighbours);
			$this->assertSame(200, $tuning->maxCandidateDepth);
			$this->assertSame(2, $tuning->maxBackfillRounds);
			$this->assertSame(9, $tuning->maxEligibilityBatchSize);
		}

		/** @return void */
		public function testRejectsOutOfRangeValues(): void {
			$invalid = [
				['minSupport', 0],
				['topRatedMinRatings', 0],
				['minNeighbourSimilarity', 0],
				['minNeighbourSimilarity', 101],
				['maxNeighbours', 0],
				['maxCandidateDepth', 49],
				['maxBackfillRounds', 0],
				['maxEligibilityBatchSize', 0],
				['minHistory', 0],
			];

			foreach ($invalid as [$name, $value]) {
				try {
					new ReconciliationTuning(...[$name => $value]);
					$this->fail("{$name} {$value} was accepted.");
				} catch (\InvalidArgumentException) {
					$this->assertTrue(true);
				}
			}
		}
	}
