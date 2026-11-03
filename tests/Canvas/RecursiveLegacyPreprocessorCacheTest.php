<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Legacy\RecursiveLegacyPreprocessor;

	class RecursiveLegacyPreprocessorCacheTest extends TestCase {
		private string $directory;

		/**
		 * Creates an isolated source and cache directory.
		 * @return void
		 */
		protected function setUp(): void {
			$this->directory = sys_get_temp_dir() . '/legacy-cache-' . uniqid('', true);
			mkdir($this->directory . '/cache', 0777, true);
		}

		/**
		 * Removes files created by the test.
		 * @return void
		 */
		protected function tearDown(): void {
			foreach (glob($this->directory . '/cache/*') ?: [] as $file) {
				unlink($file);
			}
			foreach (glob($this->directory . '/*.php') ?: [] as $file) {
				unlink($file);
			}
			rmdir($this->directory . '/cache');
			rmdir($this->directory);
		}

		/**
		 * Reuses unchanged output and refreshes a parent when its include changes.
		 * @return void
		 */
		public function testCacheHitAndChangedInclude(): void {
			$child = $this->directory . '/child.php';
			$parent = $this->directory . '/parent.php';
			file_put_contents($child, '<?php echo "first";');
			file_put_contents($parent, "<?php require '" . $child . "';");
			$processor = new RecursiveLegacyPreprocessor($this->directory . '/cache');
			$cachedParent = $processor->processFileRecursively($parent);
			$this->assertFileExists($cachedParent);
			$firstContent = file_get_contents($cachedParent);
			$this->assertStringContainsString('child.php', $firstContent);

			touch($cachedParent, 1000000000);
			$this->assertSame($cachedParent, $processor->processFileRecursively($parent));
			clearstatcache(true, $cachedParent);
			$this->assertSame(1000000000, filemtime($cachedParent));

			file_put_contents($child, '<?php echo "second";');
			touch($child, filemtime($child) + 2);
			clearstatcache(true, $child);
			$this->assertSame($cachedParent, $processor->processFileRecursively($parent));
			$this->assertNotSame($firstContent, file_get_contents($cachedParent));
			$this->assertStringContainsString('second', file_get_contents($processor->processFileRecursively($child)));
			$this->assertGreaterThan(0, $processor->clearCache());
			$this->assertFileDoesNotExist($cachedParent . '.json');
		}
	}
