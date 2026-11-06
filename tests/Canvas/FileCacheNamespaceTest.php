<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Cache\Drivers\FileCache;
	use Quellabs\Support\ComposerUtils;

	/**
	 * FileCache's namespace must be hashed into the context directory name the
	 * same way a cache key is hashed into its filename - unlike the key, it was
	 * concatenated into the filesystem path unsanitized, so a namespace value
	 * containing path-traversal characters would otherwise be used verbatim.
	 */
	class FileCacheNamespaceTest extends TestCase {
		private string $cacheDir;

		protected function setUp(): void {
			$this->cacheDir = ComposerUtils::getProjectRoot() . '/storage/cache';
		}

		private function removeDirectory(string $directory): void {
			if (!is_dir($directory)) {
				return;
			}

			foreach (glob($directory . '/*') ?: [] as $path) {
				is_dir($path) ? $this->removeDirectory($path) : unlink($path);
			}

			rmdir($directory);
		}

		public function testNamespaceDirectoryIsHashedNotLiteral(): void {
			$namespace = 'test-namespace-' . bin2hex(random_bytes(8));
			$cache = new FileCache($namespace);
			$cache->set('key', 'value');

			// The namespace string itself must not appear as a literal directory name.
			$this->assertDirectoryDoesNotExist($this->cacheDir . '/' . $namespace);
			$this->assertSame('value', $cache->get('key'));

			$this->removeDirectory($this->cacheDir . '/' . hash('sha256', $namespace));
		}

		public function testTraversalNamespaceStaysConfinedToTheCacheDirectory(): void {
			$namespace = '../../etc';
			$cache = new FileCache($namespace);
			$cache->set('key', 'value');

			// Confirms the hashed directory lands inside storage/cache rather than
			// escaping it via the traversal segments in the namespace value.
			$this->assertSame('value', $cache->get('key'));

			$hashedDir = $this->cacheDir . '/' . hash('sha256', $namespace);
			$this->assertDirectoryExists($hashedDir);

			$this->removeDirectory($hashedDir);
		}
	}
