<?php

	namespace Quellabs\Recommender\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\ColdStartPolicy;
use Quellabs\Recommender\MinHistory;
use Quellabs\Recommender\MinRatings;

	/** Unit tests for ColdStartPolicy validation. */
	class ColdStartPolicyTest extends TestCase {

		/** @return void */
		public function testDefaultsMatchRecommendationDefaults(): void {
			$policy = new ColdStartPolicy();
			$this->assertSame(1, $policy->minHistory);
			$this->assertSame(2, $policy->topRatedMinRatings);
		}

		/** @return void */
		public function testRejectsMinHistoryBelowOne(): void {
			$this->expectException(\InvalidArgumentException::class);
			new MinHistory(0);
		}

		/** @return void */
		public function testRejectsTopRatedMinRatingsBelowOne(): void {
			$this->expectException(\InvalidArgumentException::class);
			new MinRatings(0);
		}
	}
