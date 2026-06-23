<?php
	
	namespace Quellabs\Recommender\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * Unit tests for RecommendationConfig.
	 *
	 * Pure value object — no database required.
	 */
	class RecommendationConfigTest extends TestCase {
		
		// =========================================================================
		// Defaults
		// =========================================================================
		
		public function testDefaultCategory(): void {
			$config = new RecommendationConfig();
			$this->assertSame(1, $config->getCategory());
		}
		
		public function testDefaultThresholdNrCommonRatings(): void {
			$config = new RecommendationConfig();
			$this->assertSame(30, $config->getThresholdNrCommonRatings());
		}
		
		public function testDefaultThresholdMult(): void {
			$config = new RecommendationConfig();
			$this->assertSame(2, $config->getThresholdMult());
		}
		
		public function testDefaultThresholdRating(): void {
			$config = new RecommendationConfig();
			$this->assertEqualsWithDelta(0.66, $config->getThresholdRating(), 0.0001);
		}
		
		public function testDefaultCost(): void {
			$config = new RecommendationConfig();
			$this->assertEqualsWithDelta(5.0, $config->getCost(), 0.0001);
		}
		
		public function testDefaultNotInterested(): void {
			$config = new RecommendationConfig();
			$this->assertEqualsWithDelta(-1.0, $config->getNotInterested(), 0.0001);
		}
		
		public function testDefaultDirectLinksIsFalse(): void {
			$config = new RecommendationConfig();
			$this->assertFalse($config->isDirectLinks());
		}
		
		public function testDefaultDirectSlopeIsTrue(): void {
			$config = new RecommendationConfig();
			$this->assertTrue($config->isDirectSlope());
		}
		
		// =========================================================================
		// Custom values
		// =========================================================================
		
		public function testCustomCategory(): void {
			$config = new RecommendationConfig(category: 5);
			$this->assertSame(5, $config->getCategory());
		}
		
		public function testCustomThresholdRating(): void {
			$config = new RecommendationConfig(thresholdRating: 0.5);
			$this->assertEqualsWithDelta(0.5, $config->getThresholdRating(), 0.0001);
		}
		
		public function testCustomDirectLinks(): void {
			$config = new RecommendationConfig(directLinks: true);
			$this->assertTrue($config->isDirectLinks());
		}
		
		public function testCustomDirectSlope(): void {
			$config = new RecommendationConfig(directSlope: false);
			$this->assertFalse($config->isDirectSlope());
		}
		
		// =========================================================================
		// resolveCategory
		// =========================================================================
		
		public function testResolveCategoryReturnsDefaultWhenNull(): void {
			$config = new RecommendationConfig(category: 3);
			$this->assertSame(3, $config->resolveCategory(null));
		}
		
		public function testResolveCategoryReturnsOverrideWhenProvided(): void {
			$config = new RecommendationConfig(category: 3);
			$this->assertSame(7, $config->resolveCategory(7));
		}
		
		public function testResolveCategoryReturnsOneWhenNullAndDefaultIsOne(): void {
			$config = new RecommendationConfig();
			$this->assertSame(1, $config->resolveCategory(null));
		}
	}