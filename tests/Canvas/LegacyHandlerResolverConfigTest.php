<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Legacy\FileResolverInterface;
	use Quellabs\Canvas\Legacy\LegacyHandler;
	use Quellabs\Canvas\Legacy\Resolvers\DefaultFileResolver;
	use Quellabs\Canvas\Legacy\Resolvers\WordPressStyleResolver;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\DependencyInjection\Provider\SimpleBinding;
	use Quellabs\Discover\Discover;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Test-only dependency a resolver can have autowired alongside $legacyPath.
	 */
	class LegacyHandlerResolverConfigTestGreeter {
		/**
		 * @return string
		 */
		public function greet(): string {
			return 'injected';
		}
	}

	/**
	 * Test-only resolver whose constructor takes $legacyPath plus a service
	 * that only a DI container (not a plain `new`) can supply.
	 */
	class LegacyHandlerResolverConfigTestResolver implements FileResolverInterface {
		private string $legacyPath;
		private LegacyHandlerResolverConfigTestGreeter $greeter;

		/**
		 * @param string $legacyPath
		 * @param LegacyHandlerResolverConfigTestGreeter $greeter
		 */
		public function __construct(string $legacyPath, LegacyHandlerResolverConfigTestGreeter $greeter) {
			$this->legacyPath = $legacyPath;
			$this->greeter = $greeter;
		}

		/**
		 * @param string $path
		 * @param Request $request
		 * @return string|null
		 */
		public function resolve(string $path, Request $request): ?string {
			$file = rtrim($this->legacyPath, '/') . '/' . $this->greeter->greet() . '.php';
			return is_file($file) ? $file : null;
		}
	}

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

		/**
		 * Without a container, a resolver needing more than $legacyPath can't be constructed.
		 * @return void
		 */
		public function testResolverNeedingExtraDependencyFailsWithoutContainer(): void {
			$this->expectException(\ArgumentCountError::class);

			new LegacyHandler($this->legacyPath, false, [], [LegacyHandlerResolverConfigTestResolver::class]);
		}

		/**
		 * With a container, a resolver's constructor dependencies beyond $legacyPath are autowired.
		 * @return void
		 */
		public function testResolverDependencyIsAutowiredWhenContainerIsGiven(): void {
			file_put_contents($this->legacyPath . '/injected.php', '<?php echo "autowired resolver";');

			// Bypasses composer-based provider discovery, which is unrelated to
			// this test and not safe to run inside this unit test (see LegacyHelperTest)
			$container = new class extends Container {
				protected function createDiscovery(string $familyName): Discover {
					return new Discover();
				}
			};
			$container->register(new SimpleBinding(LegacyHandlerResolverConfigTestGreeter::class, new LegacyHandlerResolverConfigTestGreeter()));

			$handler = new LegacyHandler($this->legacyPath, false, [], [LegacyHandlerResolverConfigTestResolver::class], $container);
			$response = $handler->handle(Request::create('/anything'));

			$this->assertSame('autowired resolver', $response->getContent());
		}
	}
