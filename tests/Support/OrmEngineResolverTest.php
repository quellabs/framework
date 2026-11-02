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
			OrmEngineResolver::clearCache();
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			OrmEngineResolver::clearCache();
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

		/**
		 * A second call for the same directory must not re-read the mapping
		 * file: rewriting it between calls must not change the result.
		 * @return void
		 */
		public function testCachesTheMappingAcrossRepeatedCalls(): void {
			$dir = $this->makeScratchProject(['quellabs/canvas-doctrine' => []]);

			try {
				$this->assertSame(['doctrine'], OrmEngineResolver::installed($dir));

				$this->writeMapping($dir, ['quellabs/canvas-eloquent' => []]);

				$this->assertSame(['doctrine'], OrmEngineResolver::installed($dir));
			} finally {
				$this->removeScratchProject($dir);
			}
		}

		/**
		 * clearCache() forces the next call to re-read the mapping file.
		 * @return void
		 */
		public function testClearCacheForcesARereadOfTheMappingFile(): void {
			$dir = $this->makeScratchProject(['quellabs/canvas-doctrine' => []]);

			try {
				$this->assertSame(['doctrine'], OrmEngineResolver::installed($dir));

				$this->writeMapping($dir, ['quellabs/canvas-eloquent' => []]);
				OrmEngineResolver::clearCache();

				$this->assertSame(['eloquent'], OrmEngineResolver::installed($dir));
			} finally {
				$this->removeScratchProject($dir);
			}
		}

		/**
		 * Creates a scratch project directory with a bare composer.json and
		 * the given discovery mapping.
		 * @param array<string, mixed> $mapping
		 * @return string The scratch project's directory
		 */
		private function makeScratchProject(array $mapping): string {
			$dir = sys_get_temp_dir() . '/orm-engine-resolver-test-' . uniqid('', true);
			mkdir($dir . '/config', 0777, true);
			file_put_contents($dir . '/composer.json', '{}');
			$this->writeMapping($dir, $mapping);

			return $dir;
		}

		/**
		 * (Re)writes the scratch project's discovery-mapping.php.
		 * @param string $dir
		 * @param array<string, mixed> $mapping
		 * @return void
		 */
		private function writeMapping(string $dir, array $mapping): void {
			file_put_contents($dir . '/config/discovery-mapping.php', '<?php return ' . var_export($mapping, true) . ';');
		}

		/**
		 * Removes a directory created by makeScratchProject().
		 * @param string $dir
		 * @return void
		 */
		private function removeScratchProject(string $dir): void {
			unlink($dir . '/config/discovery-mapping.php');
			rmdir($dir . '/config');
			unlink($dir . '/composer.json');
			rmdir($dir);
		}
	}
