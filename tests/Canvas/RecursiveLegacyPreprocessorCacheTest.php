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
			$this->removeDirectory($this->directory);
		}

		/**
		 * Recursively removes a directory and its contents.
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

		/**
		 * A file under a vendor/ segment is returned unmodified, with nothing written to cache.
		 * @return void
		 */
		public function testVendorPathIsExcludedByDefault(): void {
			mkdir($this->directory . '/vendor/acme-lib', 0777, true);
			$vendorFile = $this->directory . '/vendor/acme-lib/library.php';
			file_put_contents($vendorFile, '<?php exit(1);');

			$processor = new RecursiveLegacyPreprocessor($this->directory . '/cache');
			$result = $processor->processFileRecursively($vendorFile);

			$this->assertSame(realpath($vendorFile), $result);
			$this->assertSame([], glob($this->directory . '/cache/*.php'));
		}

		/**
		 * An include that resolves into vendor/ is left untouched even though its parent is preprocessed.
		 * @return void
		 */
		public function testIncludedVendorFileIsExcludedWhileParentIsProcessed(): void {
			mkdir($this->directory . '/vendor/acme-lib', 0777, true);
			$vendorFile = $this->directory . '/vendor/acme-lib/library.php';
			$parent = $this->directory . '/parent.php';
			file_put_contents($vendorFile, '<?php echo "library";');
			file_put_contents($parent, "<?php require '" . $vendorFile . "';");

			$processor = new RecursiveLegacyPreprocessor($this->directory . '/cache');
			$cachedParent = $processor->processFileRecursively($parent);

			// The rewritten include still points at the original vendor file (its
			// basename, now relative), never at a preprocessed copy in the cache dir
			$this->assertStringContainsString('acme-lib/library.php', str_replace('\\', '/', file_get_contents($cachedParent)));
			$this->assertSame([], glob($this->directory . '/cache/*library*'));
		}

		/**
		 * Excluding 'lib' must not also match an unrelated file whose name merely contains "lib".
		 * @return void
		 */
		public function testExclusionMatchesWholePathSegmentsOnly(): void {
			$file = $this->directory . '/calibrate.php';
			file_put_contents($file, '<?php echo "calibrate";');

			$processor = new RecursiveLegacyPreprocessor($this->directory . '/cache', 'legacy/', ['lib']);
			$result = $processor->processFileRecursively($file);

			$this->assertNotSame(realpath($file), $result);
			$this->assertFileExists($result);
		}
	}
