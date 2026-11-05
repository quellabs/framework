<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\RateLimitStoreUnavailableException;
	use Quellabs\Canvas\Security\RateLimit\RedisRateLimitStore;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Unit and real-concurrency tests for RedisRateLimitStore.
	 *
	 * The extension-availability check is exercised directly when ext-redis isn't
	 * loaded (the constructor's fail-fast path). When ext-redis is loaded,
	 * testConcurrentAdmissionAgainstALiveRedisServer instead proves the Lua
	 * scripts' atomicity by spawning real separate PHP processes hammering the
	 * same key against a live Redis server - a single process can't race the
	 * EVAL the way independent OS processes do.
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

		/**
		 * Spawns real OS processes (not just function calls within one PHP process)
		 * all incrementing the same fixed-window key concurrently against a live
		 * Redis server, and asserts that exactly $limit of them are admitted -
		 * proving the Lua script's EVAL is atomic across process boundaries.
		 */
		public function testConcurrentAdmissionAgainstALiveRedisServer(): void {
			if (!extension_loaded('redis')) {
				$this->markTestSkipped('ext-redis is not installed in this environment.');
			}

			try {
				$probe = new \Redis();
				$probe->connect('127.0.0.1', 6379, 2.0);
				$probe->ping();
				$probe->close();
			} catch (\Throwable $e) {
				$this->markTestSkipped('No live Redis server reachable for this test: ' . $e->getMessage());
			}

			$limit = 10;
			$workers = 30;
			$window = 3600;
			$now = 1_700_000_000;
			$key = 'concurrency-test-key-' . bin2hex(random_bytes(8));

			$scriptPath = $this->writeWorkerScript();

			try {
				$processes = [];
				$pipes = [];

				for ($i = 0; $i < $workers; $i++) {
					$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

					$command = [
						PHP_BINARY,
						$scriptPath,
						$key,
						(string)$limit,
						(string)$window,
						(string)$now,
					];

					$process = proc_open($command, $descriptors, $procPipes);
					$this->assertIsResource($process, 'Failed to spawn worker process');

					$processes[] = $process;
					$pipes[] = $procPipes;
				}

				$admitted = 0;
				$rejected = 0;

				foreach ($processes as $index => $process) {
					$stdout = stream_get_contents($pipes[$index][1]);
					$stderr = stream_get_contents($pipes[$index][2]);
					fclose($pipes[$index][1]);
					fclose($pipes[$index][2]);
					$exitCode = proc_close($process);

					$this->assertSame(0, $exitCode, "worker {$index} failed: {$stderr}");

					$outcome = trim((string)$stdout);
					$this->assertContains($outcome, ['admitted', 'rejected'], "worker {$index} produced unexpected output: {$outcome} / {$stderr}");

					if ($outcome === 'admitted') {
						$admitted++;
					} else {
						$rejected++;
					}
				}

				$this->assertSame($limit, $admitted, 'exactly $limit requests must be admitted under concurrency');
				$this->assertSame($workers - $limit, $rejected);
			} finally {
				@unlink($scriptPath);
				$this->cleanUpKey($key, $window, $now);
			}
		}

		/**
		 * Deletes the Redis key the worker processes wrote to, mirroring
		 * RedisRateLimitStore::buildKey() so the test leaves no state behind.
		 * @param string $key
		 * @param int $window
		 * @param int $now
		 * @return void
		 */
		private function cleanUpKey(string $key, int $window, int $now): void {
			try {
				$windowStart = intdiv($now, $window) * $window;
				$redisKey = 'rate_limit:' . hash('sha256', $key . ':fixed:' . $windowStart);

				$redis = new \Redis();
				$redis->connect('127.0.0.1', 6379, 2.0);
				$redis->del($redisKey);
				$redis->close();
			} catch (\Throwable) {
				// Best-effort cleanup; the key would expire on its own regardless.
			}
		}

		/**
		 * Writes a standalone worker script to a temp file: it boots the framework's
		 * own autoloader, builds a RedisRateLimitStore against the default local
		 * connection, and reports admitted/rejected for one incrementFixedWindow()
		 * call. Kept as a generated temp file (not a checked-in fixture) since its
		 * only job is to be executed as a separate OS process by this test.
		 * @return string Path to the generated worker script
		 */
		private function writeWorkerScript(): string {
			$autoloadPath = ComposerUtils::getProjectRoot() . '/vendor/autoload.php';

			$source = <<<'PHP'
				<?php
				require '__AUTOLOAD_PLACEHOLDER__';

				$key = $argv[1];
				$limit = $argv[2];
				$window = $argv[3];
				$now = $argv[4];

				$store = new \Quellabs\Canvas\Security\RateLimit\RedisRateLimitStore();
				$result = $store->incrementFixedWindow($key, (int)$limit, (int)$window, (int)$now);

				echo $result->isExceeded() ? 'rejected' : 'admitted';
				PHP;

			$source = str_replace('__AUTOLOAD_PLACEHOLDER__', $autoloadPath, $source);

			$path = sys_get_temp_dir() . '/canvas_redis_rate_limit_worker_' . bin2hex(random_bytes(8)) . '.php';
			file_put_contents($path, $source);

			return $path;
		}
	}
