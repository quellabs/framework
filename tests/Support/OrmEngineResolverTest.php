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
	}
