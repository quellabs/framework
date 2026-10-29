<?php

	namespace Quellabs\Recommender\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\Internal\ImplicitRating;

	/** Unit tests for the click rule shared by members and visitors. */
	class ImplicitRatingTest extends TestCase {

		/** @return void */
		public function testFirstClickIsFirstClickRating(): void {
			$this->assertEqualsWithDelta(0.7, ImplicitRating::afterClick(null), 0.0001);
		}

		/** @return void */
		public function testFurtherClicksRaiseByStep(): void {
			$this->assertEqualsWithDelta(0.71, ImplicitRating::afterClick(0.7), 0.0001);
		}

		/** @return void */
		public function testClicksCapAtPurchase(): void {
			$this->assertEqualsWithDelta(1.0, ImplicitRating::afterClick(0.995), 0.0001);
		}
	}
