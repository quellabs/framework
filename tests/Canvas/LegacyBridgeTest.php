<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Legacy\LegacyBridge;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\Discover\Discover;

	class LegacyBridgeTest extends TestCase {

		/**
		 * Resets the bridge's static state so tests don't leak into each other.
		 * @return void
		 */
		protected function tearDown(): void {
			$property = new \ReflectionProperty(LegacyBridge::class, 'initialized');
			$property->setAccessible(true);
			$property->setValue(null, false);
		}

		/**
		 * container() throws a descriptive exception instead of a raw typed-property error.
		 * @return void
		 */
		public function testContainerThrowsWhenNotInitialized(): void {
			$this->assertFalse(LegacyBridge::isInitialized());

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('LegacyBridge is not initialized');

			LegacyBridge::container();
		}

		/**
		 * canvas() propagates the same exception when called before initialize().
		 * @return void
		 */
		public function testCanvasHelperThrowsWhenNotInitialized(): void {
			$this->expectException(\RuntimeException::class);

			\Quellabs\Canvas\Legacy\canvas();
		}

		/**
		 * After initialize(), container() and canvas() return the stored container.
		 * @return void
		 */
		public function testContainerAndHelperReturnStoredContainerAfterInitialize(): void {
			// Bypasses composer-based provider discovery, which is unrelated
			// to LegacyBridge and not safe to run inside this unit test
			$container = new class extends Container {
				protected function createDiscovery(string $familyName): Discover {
					return new Discover();
				}
			};

			LegacyBridge::initialize($container);

			$this->assertTrue(LegacyBridge::isInitialized());
			$this->assertSame($container, LegacyBridge::container());
			$this->assertSame($container, \Quellabs\Canvas\Legacy\canvas());
		}
	}
