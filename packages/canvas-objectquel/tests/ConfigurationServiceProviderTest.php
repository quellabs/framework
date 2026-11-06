<?php

	namespace Quellabs\CanvasObjectQuel\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasObjectQuel\ConfigurationServiceProvider;
	use Quellabs\ObjectQuel\Configuration;

	/**
	 * ConfigurationServiceProvider::createInstance() caches its Configuration
	 * in a static singleton, so every test resets it first -- otherwise the
	 * first test's instance would leak into the rest regardless of the
	 * config each one passes in.
	 */
	class ConfigurationServiceProviderTest extends TestCase {

		protected function setUp(): void {
			$property = new \ReflectionProperty(ConfigurationServiceProvider::class, 'instance');
			$property->setAccessible(true);
			$property->setValue(null, null);
		}

		public function testMetadataCachingIsEnabledByDefaultWhenAbsentFromConfig(): void {
			$provider = new ConfigurationServiceProvider();
			$provider->setConfig([]);

			$configuration = $provider->createInstance(Configuration::class, [], []);

			self::assertTrue($configuration->useMetadataCache());
			self::assertNotSame('', $configuration->getMetadataCachePath());
		}

		public function testExplicitEmptyStringDisablesMetadataCaching(): void {
			$provider = new ConfigurationServiceProvider();
			$provider->setConfig(['metadata_cache_path' => '']);

			$configuration = $provider->createInstance(Configuration::class, [], []);

			self::assertFalse($configuration->useMetadataCache());
		}

		public function testExplicitPathEnablesMetadataCachingAtThatPath(): void {
			$provider = new ConfigurationServiceProvider();
			$provider->setConfig(['metadata_cache_path' => '/tmp/some-cache-dir']);

			$configuration = $provider->createInstance(Configuration::class, [], []);

			self::assertTrue($configuration->useMetadataCache());
			self::assertSame('/tmp/some-cache-dir', $configuration->getMetadataCachePath());
		}
	}
