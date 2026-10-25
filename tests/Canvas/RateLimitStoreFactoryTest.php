<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Configuration\Configuration;
	use Quellabs\Canvas\Security\RateLimit\FileRateLimitStore;
	use Quellabs\Canvas\Security\RateLimit\RateLimitStoreFactory;

	/**
	 * Unit tests for RateLimitStoreFactory, which builds the RateLimitStoreInterface
	 * that Kernel binds into the DI container from config/rate_limit.php.
	 */
	class RateLimitStoreFactoryTest extends TestCase {

		public function testDefaultsToFileDriverWhenConfigIsEmpty(): void {
			$store = RateLimitStoreFactory::create(new Configuration([]));

			$this->assertInstanceOf(FileRateLimitStore::class, $store);
		}

		public function testUsesTheConfiguredDriversClassEntry(): void {
			$store = RateLimitStoreFactory::create(new Configuration([
				'driver'  => 'file',
				'drivers' => [
					'file' => ['class' => FileRateLimitStore::class],
				],
			]));

			$this->assertInstanceOf(FileRateLimitStore::class, $store);
		}

		public function testThrowsWhenTheConfiguredClassDoesNotExist(): void {
			$this->expectException(\InvalidArgumentException::class);

			RateLimitStoreFactory::create(new Configuration([
				'driver'  => 'file',
				'drivers' => [
					'file' => ['class' => 'Quellabs\\Canvas\\Security\\RateLimit\\DoesNotExist'],
				],
			]));
		}

		public function testThrowsWhenTheConfiguredClassDoesNotImplementTheInterface(): void {
			$this->expectException(\RuntimeException::class);

			RateLimitStoreFactory::create(new Configuration([
				'driver'  => 'file',
				'drivers' => [
					'file' => ['class' => \stdClass::class],
				],
			]));
		}

		public function testPassesTheDriverConfigArrayToTheStoreConstructor(): void {
			$path = sys_get_temp_dir() . '/canvas_rate_limit_factory_test_' . bin2hex(random_bytes(8));

			try {
				$store = RateLimitStoreFactory::create(new Configuration([
					'driver'  => 'file',
					'drivers' => [
						'file' => [
							'class' => FileRateLimitStore::class,
							'path'  => $path,
						],
					],
				]));

				$store->incrementFixedWindow('k', 5, 60, 1000);
				$this->assertDirectoryExists($path);
			} finally {
				foreach (glob($path . '/*') ?: [] as $file) {
					@unlink($file);
				}

				@rmdir($path);
			}
		}
	}
