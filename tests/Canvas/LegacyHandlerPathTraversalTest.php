<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\RouteNotFoundException;
	use Quellabs\Canvas\Legacy\LegacyHandler;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * LegacyHandler::isSafeFile() must reject any resolved file whose canonical
	 * path falls outside legacyPath, even when a resolver's own concatenation
	 * let a ".." segment through.
	 */
	class LegacyHandlerPathTraversalTest extends TestCase {
		private string $rootDir;
		private string $legacyPath;

		/**
		 * Creates an isolated root directory containing a "legacy" subdirectory
		 * and a sibling file outside it that a traversal attempt would target.
		 * @return void
		 */
		protected function setUp(): void {
			$this->rootDir = sys_get_temp_dir() . '/legacy-traversal-' . uniqid('', true);
			$this->legacyPath = $this->rootDir . '/legacy';
			mkdir($this->legacyPath, 0777, true);

			file_put_contents($this->rootDir . '/secret.php', '<?php echo "secret";');
			file_put_contents($this->legacyPath . '/page.php', '<?php echo "legacy page";');
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			$this->removeDirectory($this->rootDir);
		}

		/**
		 * @param string $directory Directory to remove
		 * @return void
		 */
		private function removeDirectory(string $directory): void {
			foreach (glob($directory . '/*') ?: [] as $path) {
				is_dir($path) ? $this->removeDirectory($path) : unlink($path);
			}

			rmdir($directory);
		}

		/**
		 * A ".." segment that resolves outside legacyPath must not be included,
		 * even though the file exists, is readable, and ends in ".php".
		 * @return void
		 */
		public function testPathTraversalOutsideLegacyPathIsRejected(): void {
			$handler = new LegacyHandler($this->legacyPath, false);

			$this->expectException(RouteNotFoundException::class);

			$handler->handle(Request::create('/../secret'));
		}

		/**
		 * A normal request that stays inside legacyPath keeps working.
		 * @return void
		 */
		public function testRequestWithinLegacyPathStillResolves(): void {
			$handler = new LegacyHandler($this->legacyPath, false);

			$response = $handler->handle(Request::create('/page'));

			$this->assertSame('legacy page', $response->getContent());
		}
	}
