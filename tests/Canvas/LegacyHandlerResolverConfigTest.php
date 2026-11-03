<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Legacy\FileResolverInterface;
	use Quellabs\Canvas\Legacy\LegacyHandler;
	use Quellabs\Canvas\Legacy\Resolvers\DefaultFileResolver;
	use Quellabs\Canvas\Legacy\Resolvers\WordPressStyleResolver;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * LegacyHandler's resolver chain is configurable via its constructor so that
	 * app.php's legacy_resolvers option can wire up resolvers like
	 * WordPressStyleResolver without requiring manual addResolver() calls.
	 */
	class LegacyHandlerResolverConfigTest extends TestCase {
		private string $legacyPath;

		/**
		 * Creates an isolated legacy directory.
		 * @return void
		 */
		protected function setUp(): void {
			$this->legacyPath = sys_get_temp_dir() . '/legacy-resolver-' . uniqid('', true);
			mkdir($this->legacyPath, 0777, true);
		}

		/**
		 * Recursively removes the legacy directory.
		 * @return void
		 */
		protected function tearDown(): void {
			$this->removeDirectory($this->legacyPath);
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
		 * With no resolver classes given, the constructor still defaults to DefaultFileResolver.
		 * @return void
		 */
		public function testDefaultsToDefaultFileResolverWhenNoneConfigured(): void {
			file_put_contents($this->legacyPath . '/page.php', '<?php echo "default resolver";');

			$handler = new LegacyHandler($this->legacyPath, false);
			$response = $handler->handle(Request::create('/page'));

			$this->assertSame('default resolver', $response->getContent());
		}

		/**
		 * Resolver classes passed in are instantiated with $legacyPath and tried in order.
		 * @return void
		 */
		public function testConfiguredResolversAreTriedInOrder(): void {
			mkdir($this->legacyPath . '/posts');
			file_put_contents($this->legacyPath . '/posts/2024-03-my-post.php', '<?php echo "wordpress style";');
			file_put_contents($this->legacyPath . '/page.php', '<?php echo "default style";');

			// WordPressStyleResolver concatenates its legacy path directly
			// (unlike DefaultFileResolver, it doesn't rtrim/re-add the separator)
			$handler = new LegacyHandler($this->legacyPath . '/', false, [], [
				WordPressStyleResolver::class,
				DefaultFileResolver::class,
			]);

			$wordpressResponse = $handler->handle(Request::create('/2024/03/my-post'));
			$this->assertSame('wordpress style', $wordpressResponse->getContent());

			$defaultResponse = $handler->handle(Request::create('/page'));
			$this->assertSame('default style', $defaultResponse->getContent());
		}

		/**
		 * A resolver class that doesn't exist must fail loudly, not silently resolve nothing.
		 * @return void
		 */
		public function testUnknownResolverClassThrows(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("does not exist");

			new LegacyHandler($this->legacyPath, false, [], ['Nonexistent\\ResolverClass']);
		}

		/**
		 * A configured class that doesn't implement FileResolverInterface must fail loudly.
		 * @return void
		 */
		public function testResolverClassNotImplementingInterfaceThrows(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage(FileResolverInterface::class);

			new LegacyHandler($this->legacyPath, false, [], [\stdClass::class]);
		}
	}
