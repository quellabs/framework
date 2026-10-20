<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\RateLimitStoreUnavailableException;
	use Quellabs\Canvas\Security\RateLimit\RedisRateLimitStore;

	/**
	 * Unit tests for RedisRateLimitStore.
	 *
	 * The extension-availability check is exercised directly (this environment has
	 * no redis extension, so the constructor's fail-fast path is exactly what runs).
	 * Everything downstream of a live connection - the Lua scripts' atomicity,
	 * concurrent admission - requires an actual Redis server and is out of reach of
	 * a unit test; those cases are skipped rather than faked with a mock of an
	 * extension class that isn't loaded.
	 */
	class RedisRateLimitStoreTest extends TestCase {

		public function testConstructorThrowsUnavailableWhenRedisExtensionIsMissing(): void {
			if (extension_loaded('redis')) {
				$this->markTestSkipped('ext-redis is loaded in this environment; the fail-fast path cannot be exercised here.');
			}

			$this->expectException(RateLimitStoreUnavailableException::class);
			$this->expectExceptionMessageMatches('/redis PHP extension/');

			new RedisRateLimitStore();
		}

		public function testConcurrentAdmissionAgainstALiveRedisServer(): void {
			if (!extension_loaded('redis')) {
				$this->markTestSkipped('ext-redis is not installed in this environment.');
			}

			$this->markTestIncomplete(
				'Requires a live Redis server; run manually in an environment with one configured.'
			);
		}
	}
