<?php

	namespace Quellabs\Recommender\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\MinRatings;
	use Quellabs\Recommender\MinSupport;

	/** Unit tests for the MinSupport and MinRatings threshold value objects. */
	class ThresholdValueObjectsTest extends TestCase {

		/** @return void */
		public function testMinSupportDefaultsToOne(): void {
			$this->assertSame(1, (new MinSupport())->value);
		}

		/** @return void */
		public function testMinSupportRejectsBelowOne(): void {
			$this->expectException(\InvalidArgumentException::class);
			new MinSupport(0);
		}

		/** @return void */
		public function testMinRatingsDefaultsToOne(): void {
			$this->assertSame(1, (new MinRatings())->value);
		}

		/** @return void */
		public function testMinRatingsRejectsBelowOne(): void {
			$this->expectException(\InvalidArgumentException::class);
			new MinRatings(0);
		}
	}
