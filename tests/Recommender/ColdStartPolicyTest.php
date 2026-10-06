<?php

	namespace Quellabs\Recommender\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\ColdStartPolicy;

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
			new ColdStartPolicy(minHistory: 0);
		}

		/** @return void */
		public function testRejectsTopRatedMinRatingsBelowOne(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ColdStartPolicy(topRatedMinRatings: 0);
		}
	}
