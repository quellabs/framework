<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Support\ComposerUtils;

	/**
	 * ServiceProvider::getDefaults()['metadata_cache_path'] must match
	 * canvas-objectquel\ConfigurationServiceProvider's own default
	 * exactly ({root}/storage/objectquel/annotations). A Sculpt command
	 * (e.g. quel:clear-cache) builds its own Configuration from this
	 * provider, separately from the one the running app builds; when a
	 * project doesn't set 'metadata_cache_path' explicitly, each side
	 * falls back to its own default. A drift between the two — this
	 * class once defaulted to {root}/storage/annotations, missing the
	 * 'objectquel' segment — means a Sculpt command silently clears or
	 * inspects a directory the app was never actually caching into,
	 * leaving the real cache stale with no error of any kind.
	 */
	class MetadataCachePathDefaultTest extends TestCase {

		public function testDefaultMatchesTheRunningAppsDefaultAnnotationCacheDirectory(): void {
			$expected = ComposerUtils::getProjectRoot() . '/storage/objectquel/annotations';

			self::assertSame($expected, ServiceProvider::getDefaults()['metadata_cache_path']);
		}

		public function testAbsentFromConfigResolvesToThatSameDefault(): void {
			$provider = new ServiceProvider();
			$provider->setConfig([]);

			$expected = ComposerUtils::getProjectRoot() . '/storage/objectquel/annotations';

			self::assertSame($expected, $provider->getConfiguration()->getMetadataCachePath());
		}
	}
