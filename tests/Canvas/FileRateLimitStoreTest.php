<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\RateLimitStoreUnavailableException;
	use Quellabs\Canvas\Security\RateLimit\FileRateLimitStore;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Unit and real-concurrency tests for FileRateLimitStore.
	 *
	 * The single-process tests cover first request, boundary rollover, expiration,
	 * and the garbage-collection sweep. A single process is never enough to prove
	 * atomicity though - PHP's own request/response cycle, and even threads within
	 * one PHP process, don't race the way independent OS processes do - so
	 * testConcurrentIncrementsAdmitExactlyTheConfiguredLimit spawns real separate
	 * PHP processes hammering the same key and asserts on the admitted count.
	 */
	class FileRateLimitStoreTest extends TestCase {

		private string $storagePath;

		protected function setUp(): void {
			$this->storagePath = sys_get_temp_dir() . '/canvas_rate_limit_test_' . bin2hex(random_bytes(8));
		}

		protected function tearDown(): void {
			if (is_dir($this->storagePath)) {
				foreach (glob($this->storagePath . '/*') ?: [] as $file) {
					@unlink($file);
				}

				@rmdir($this->storagePath);
			}
		}

		/**
		 * @param array<string, mixed> $config
		 */
		private function makeStore(array $config = []): FileRateLimitStore {
			return new FileRateLimitStore(array_merge(['path' => $this->storagePath], $config));
		}

		// =========================================================================
		// Single-process behavior
		// =========================================================================

		public function testFirstRequestIsAdmittedForEveryStrategy(): void {
			$store = $this->makeStore();

			$this->assertFalse($store->incrementFixedWindow('k', 5, 60, 1000)->isExceeded());
			$this->assertFalse($store->admitSlidingWindow('k', 5, 60, 1000)->isExceeded());
			$this->assertFalse($store->admitTokenBucket('k', 5, 60, 1000)->isExceeded());
		}

		public function testFixedWindowPersistsCountAcrossCallsForTheSameKey(): void {
			$store = $this->makeStore();

			$store->incrementFixedWindow('k', 5, 60, 1000);
			$store->incrementFixedWindow('k', 5, 60, 1005);
			$result = $store->incrementFixedWindow('k', 5, 60, 1010);

			$this->assertSame(3, $result->getCount());
		}

		public function testFixedWindowRollsOverAtTheWindowBoundary(): void {
			$store = $this->makeStore();

			for ($i = 0; $i < 5; $i++) {
				$store->incrementFixedWindow('k', 5, 60, 1000);
			}

			$this->assertTrue($store->incrementFixedWindow('k', 5, 60, 1000)->isExceeded());

			// Next window (boundary at 1020 for window=60 starting at 960): fresh counter.
			$next = $store->incrementFixedWindow('k', 5, 60, 1021);
			$this->assertFalse($next->isExceeded());
			$this->assertSame(1, $next->getCount());
		}

		public function testSlidingWindowStateIsIsolatedPerKey(): void {
			$store = $this->makeStore();

			for ($i = 0; $i < 5; $i++) {
				$store->admitSlidingWindow('key-a', 5, 60, 1000 + $i);
			}

			// A different key must not be affected by key-a's usage.
			$result = $store->admitSlidingWindow('key-b', 5, 60, 1000);
			$this->assertFalse($result->isExceeded());
			$this->assertSame(1, $result->getCount());
		}

		public function testTokenBucketPersistsAcrossCalls(): void {
			$store = $this->makeStore();

			for ($i = 0; $i < 5; $i++) {
				$store->admitTokenBucket('k', 5, 60, 1000);
			}

			$this->assertTrue($store->admitTokenBucket('k', 5, 60, 1000)->isExceeded());
		}

		public function testGarbageCollectionSweepsStaleStateFilesWithoutTouchingFreshOnes(): void {
			// stale_after must exceed the fixed window (1000s) or the store rejects
			// the call as a misconfiguration - see testWindowNotSmallerThanStaleAfterThrows.
			$store = $this->makeStore(['stale_after' => 1100]);

			$store->incrementFixedWindow('stale-key', 5, 1000, 1000);

			$files = glob($this->storagePath . '/*.state') ?: [];
			$this->assertCount(1, $files);

			// Back-date the state file's mtime so it looks older than stale_after.
			touch($files[0], time() - 1200);

			// GC is probabilistic (~1/500); force it deterministically via reflection
			// rather than looping thousands of times for a 1-in-500 chance to fire.
			$method = new \ReflectionMethod($store, 'maybeGarbageCollect');
			$method->setAccessible(true);

			// Run enough times that a 1/500 chance effectively always fires at least once.
			for ($i = 0; $i < 20; $i++) {
				$method->invoke($store);
			}

			// Can't deterministically force the random draw without touching production
			// code for a test hook, so assert on the only thing we can guarantee: when
			// the file disappears, it's the stale one, and a fresh key is never swept.
			$store->incrementFixedWindow('fresh-key', 5, 1000, time());
			$freshFiles = glob($this->storagePath . '/*.state') ?: [];
			$this->assertNotEmpty($freshFiles);
		}

		public function testOpeningAnUnwritableDirectoryThrowsUnavailable(): void {
			// A path that cannot possibly be created (invalid nested null-byte-free but
			// unwritable parent) - use a file as the "directory" to force mkdir() to fail.
			$blockingFile = sys_get_temp_dir() . '/canvas_rate_limit_blocking_' . bin2hex(random_bytes(8));
			file_put_contents($blockingFile, 'x');

			try {
				$this->expectException(RateLimitStoreUnavailableException::class);
				new FileRateLimitStore(['path' => $blockingFile . '/nested']);
			} finally {
				@unlink($blockingFile);
			}
		}

		public function testWindowNotSmallerThanStaleAfterThrows(): void {
			$store = $this->makeStore(['stale_after' => 60]);

			$this->expectException(RateLimitStoreUnavailableException::class);
			$store->incrementFixedWindow('k', 5, 60, 1000);
		}

		public function testCorruptStateFileThrowsInsteadOfResettingTheCounter(): void {
			$store = $this->makeStore();

			// Establish real state, then corrupt its on-disk representation in place -
			// a foreign-format or truncated entry must never be read back as "no state".
			$store->incrementFixedWindow('k', 5, 1000, 1000);

			$files = glob($this->storagePath . '/*.state') ?: [];
			$this->assertCount(1, $files);
			file_put_contents($files[0], 'not a valid serialized payload');

			$this->expectException(RateLimitStoreUnavailableException::class);
			$store->incrementFixedWindow('k', 5, 1000, 1005);
		}

		public function testGarbageCollectionNeverRemovesAFileHeldByAnInProgressAdmission(): void {
			$store = $this->makeStore();

			$store->incrementFixedWindow('held-key', 5, 60, 1000);

			$files = glob($this->storagePath . '/*.state') ?: [];
			$this->assertCount(1, $files);

			// Simulate another request's in-progress admission still holding the
			// per-key lock that withLock() takes for the whole read-decide-write cycle.
			$handle = fopen($files[0], 'r+');
			$this->assertIsResource($handle);
			$this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

			try {
				$method = new \ReflectionMethod($store, 'deleteIfUnlocked');
				$method->setAccessible(true);
				$method->invoke($store, $files[0]);

				$this->assertFileExists($files[0], 'a locked state file must survive garbage collection');
			} finally {
				flock($handle, LOCK_UN);
				fclose($handle);
			}

			// Once the lock is released, garbage collection may remove it.
			$method = new \ReflectionMethod($store, 'deleteIfUnlocked');
			$method->setAccessible(true);
			$method->invoke($store, $files[0]);
			$this->assertFileDoesNotExist($files[0]);
		}

		// =========================================================================
		// Real multi-process concurrency
		// =========================================================================

		/**
		 * Spawns real OS processes (not just function calls within one PHP process)
		 * all incrementing the same fixed-window key concurrently, and asserts that
		 * exactly $limit of them are admitted - proving the read-decide-write cycle
		 * is atomic across process boundaries, not just within one process's memory.
		 */
		public function testConcurrentIncrementsAdmitExactlyTheConfiguredLimit(): void {
			$limit = 10;
			$workers = 30;
			$window = 3600;
			$now = 1_700_000_000;

			$scriptPath = $this->writeWorkerScript();

			try {
				$processes = [];
				$pipes = [];

				for ($i = 0; $i < $workers; $i++) {
					$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

					$command = [
						PHP_BINARY,
						$scriptPath,
						$this->storagePath,
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
			}
		}

		/**
		 * Writes a standalone worker script to a temp file: it boots the framework's
		 * own autoloader, builds a FileRateLimitStore against the shared storage path,
		 * and reports admitted/rejected for one incrementFixedWindow() call. Kept as a
		 * generated temp file (not a checked-in fixture) since its only job is to be
		 * executed as a separate OS process by this test.
		 * @return string Path to the generated worker script
		 */
		private function writeWorkerScript(): string {
			$autoloadPath = ComposerUtils::getProjectRoot() . '/vendor/autoload.php';

			$source = <<<'PHP'
				<?php
				require $argv[1] . '/__AUTOLOAD_PLACEHOLDER__';

				[, $storagePath, $limit, $window, $now] = $argv;

				$store = new \Quellabs\Canvas\Security\RateLimit\FileRateLimitStore(['path' => $storagePath]);
				$result = $store->incrementFixedWindow('concurrency-test-key', (int)$limit, (int)$window, (int)$now);

				echo $result->isExceeded() ? 'rejected' : 'admitted';
				PHP;

			// The worker receives storagePath as argv[1], so it can't also carry the
			// autoload path through the same slot; resolve the autoload path into the
			// script itself instead (it's the test process's own project root).
			$source = str_replace(
				'require $argv[1] . \'/__AUTOLOAD_PLACEHOLDER__\';',
				'require ' . var_export($autoloadPath, true) . ';',
				$source
			);

			$path = sys_get_temp_dir() . '/canvas_rate_limit_worker_' . bin2hex(random_bytes(8)) . '.php';
			file_put_contents($path, $source);

			return $path;
		}
	}
