<?php

	namespace Quellabs\Support\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Support\OrmEngineResolver;

	class OrmEngineResolverTest extends TestCase {

		/**
		 * @var string
		 */
		private string $fixturesDir;

		/**
		 * @return void
		 */
		protected function setUp(): void {
			$this->fixturesDir = __DIR__ . '/Fixtures/OrmProjects';
		}

		/**
		 * @return void
		 */
		public function testResolvesDoctrineWhenCanvasDoctrineIsDiscovered(): void {
			$this->assertSame('doctrine', OrmEngineResolver::resolve($this->fixturesDir . '/doctrine'));
		}

		/**
		 * @return void
		 */
		public function testResolvesEloquentWhenCanvasEloquentIsDiscovered(): void {
			$this->assertSame('eloquent', OrmEngineResolver::resolve($this->fixturesDir . '/eloquent'));
		}

		/**
		 * @return void
		 */
		public function testResolvesObjectQuelWhenOnlyObjectQuelIsDiscovered(): void {
			$this->assertSame('objectquel', OrmEngineResolver::resolve($this->fixturesDir . '/objectquel'));
		}

		/**
		 * @return void
		 */
		public function testFallsBackToObjectQuelWhenNoMappingFileExists(): void {
			$this->assertSame('objectquel', OrmEngineResolver::resolve($this->fixturesDir . '/none'));
		}

		/**
		 * @return void
		 */
		public function testInstalledReturnsSingleOrmWhenOnlyOneIsDiscovered(): void {
			$this->assertSame(['doctrine'], OrmEngineResolver::installed($this->fixturesDir . '/doctrine'));
			$this->assertSame(['eloquent'], OrmEngineResolver::installed($this->fixturesDir . '/eloquent'));
			$this->assertSame(['objectquel'], OrmEngineResolver::installed($this->fixturesDir . '/objectquel'));
		}

		/**
		 * @return void
		 */
		public function testInstalledReturnsEmptyArrayWhenNoMappingFileExists(): void {
			$this->assertSame([], OrmEngineResolver::installed($this->fixturesDir . '/none'));
		}

		/**
		 * @return void
		 */
		public function testInstalledReturnsEveryDiscoveredOrmInOrmPackagesOrder(): void {
			$this->assertSame(['doctrine', 'eloquent'], OrmEngineResolver::installed($this->fixturesDir . '/multiple'));
		}

		/**
		 * @return void
		 */
		public function testCountMatchesTheNumberOfInstalledOrms(): void {
			$this->assertSame(1, OrmEngineResolver::count($this->fixturesDir . '/doctrine'));
			$this->assertSame(2, OrmEngineResolver::count($this->fixturesDir . '/multiple'));
			$this->assertSame(0, OrmEngineResolver::count($this->fixturesDir . '/none'));
		}

		/**
		 * @return void
		 */
		public function testResolvePicksTheFirstOrmPackagesMatchWhenMultipleAreInstalled(): void {
			$this->assertSame('doctrine', OrmEngineResolver::resolve($this->fixturesDir . '/multiple'));
		}
	}
