<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\RateLimitStoreUnavailableException;
	use Quellabs\Canvas\Security\RateLimit\MemcachedRateLimitStore;

	/**
	 * Unit tests for MemcachedRateLimitStore.
	 *
	 * The extension-availability check is exercised directly (this environment has
	 * no memcached extension, so the constructor's fail-fast path is exactly what
	 * runs). The CAS retry loop's atomicity requires a live Memcached server and is
	 * out of reach of a unit test; that case is skipped rather than faked with a
	 * mock of an extension class that isn't loaded.
	 */
	class MemcachedRateLimitStoreTest extends TestCase {

		public function testConstructorThrowsUnavailableWhenMemcachedExtensionIsMissing(): void {
			if (extension_loaded('memcached')) {
				$this->markTestSkipped('ext-memcached is loaded in this environment; the fail-fast path cannot be exercised here.');
			}

			$this->expectException(RateLimitStoreUnavailableException::class);
			$this->expectExceptionMessageMatches('/memcached PHP extension/');

			new MemcachedRateLimitStore();
		}

		public function testConcurrentAdmissionAgainstALiveMemcachedServer(): void {
			if (!extension_loaded('memcached')) {
				$this->markTestSkipped('ext-memcached is not installed in this environment.');
			}

			$this->markTestIncomplete(
				'Requires a live Memcached server; run manually in an environment with one configured.'
			);
		}
	}
