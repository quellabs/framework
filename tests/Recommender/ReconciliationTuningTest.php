<?php

namespace Quellabs\Recommender\Tests;

use PHPUnit\Framework\TestCase;
use Quellabs\Recommender\Reconciliation\ReconciliationTuning;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\RecommendationSource;

/** Unit tests for ReconciliationTuning and SourceSettings validation. */
class ReconciliationTuningTest extends TestCase {

	/** @return void */
	public function testStoresOverrides(): void {
		$tuning = new ReconciliationTuning(
			new SourceSettings(minSupport: 3, maxNeighbours: 7),
			maxCandidateDepth: 200,
			maxEligibilityBatchSize: 9,
			minHistory: 4,
			coldStartSources: [RecommendationSource::TopRated],
		);

		$this->assertSame(3, $tuning->sources->minSupport);
		$this->assertSame(7, $tuning->sources->maxNeighbours);
		$this->assertSame(200, $tuning->maxCandidateDepth);
		$this->assertSame(9, $tuning->maxEligibilityBatchSize);
		$this->assertSame(4, $tuning->minHistory);
		$this->assertSame([RecommendationSource::TopRated], $tuning->coldStartSources);
	}

	/** @return void */
	public function testColdStartSourcesDefaultsToNull(): void {
		$this->assertNull((new ReconciliationTuning())->coldStartSources);
	}

	/** @return void */
	public function testColdStartSourcesRejectsEmptyOrNonSourceValues(): void {
		foreach ([[], ['not-a-source']] as $coldStartSources) {
			try {
				new ReconciliationTuning(coldStartSources: $coldStartSources);
				$this->fail('Invalid cold-start sources were accepted.');
			} catch (\InvalidArgumentException) {
				$this->assertTrue(true);
			}
		}
	}

	/** @return void */
	public function testSourceSettingsRejectOutOfRangeValues(): void {
		$invalid = [
			['minSupport', 0],
			['topRatedMinRatings', 0],
			['minNeighbourSimilarity', 0],
			['minNeighbourSimilarity', 101],
			['maxNeighbours', 0],
		];

		foreach ($invalid as [$name, $value]) {
			try {
				new SourceSettings(...[$name => $value]);
				$this->fail("{$name} {$value} was accepted.");
			} catch (\InvalidArgumentException) {
				$this->assertTrue(true);
			}
		}
	}

	/** @return void */
	public function testTuningRejectsOutOfRangeValues(): void {
		$invalid = [
			['maxCandidateDepth', 49],
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
