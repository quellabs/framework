<?php

	namespace Quellabs\Discover\Tests\Utilities;

	use PHPUnit\Framework\TestCase;
	use Psr\Log\AbstractLogger;
	use Quellabs\Discover\Utilities\ComposerInstalledLoader;

	/**
	 * Unit tests for ComposerInstalledLoader.
	 * parseJsonFile() is tested through a subclass because getData() resolves installed.json from the real vendor dir.
	 */
	class ComposerInstalledLoaderTest extends TestCase {

		private string $tempDir;

		/** @var list<array{level: mixed, message: string, context: array<mixed>}> */
		private array $logRecords = [];

		/**
		 * Creates an empty temp directory for fixture files.
		 * @return void
		 */
		protected function setUp(): void {
			$this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'discover_loader_' . uniqid('', true);
			mkdir($this->tempDir, 0777, true);
			$this->logRecords = [];
		}

		/**
		 * Removes the temp directory and its contents.
		 * @return void
		 */
		protected function tearDown(): void {
			$this->removeDirectory($this->tempDir);
		}

		// =========================================================================
		// parseJsonFile(): supported formats
		// =========================================================================

		public function testParsesComposer2PackagesFormat(): void {
			$path = $this->writeJson([
				'packages' => [
					['name' => 'vendor/a', 'extra' => ['discover' => ['providers' => ['A']]]],
					['name' => 'vendor/b', 'extra' => ['branch-alias' => ['dev-main' => '1.x-dev']]],
				],
			]);

			$this->assertSame([
				'vendor/a' => ['discover' => ['providers' => ['A']]],
				'vendor/b' => ['branch-alias' => ['dev-main' => '1.x-dev']],
			], $this->parse($path));
		}

		public function testParsesComposer1PackageListFormat(): void {
			$path = $this->writeJson([
				['name' => 'vendor/a', 'extra' => ['discover' => ['providers' => ['A']]]],
			]);

			$this->assertSame(['vendor/a' => ['discover' => ['providers' => ['A']]]], $this->parse($path));
		}

		public function testReturnsEmptyArrayForUnrecognizedStructure(): void {
			$path = $this->writeJson(['something' => 'else']);

			$this->assertSame([], $this->parse($path));
		}

		// =========================================================================
		// parseJsonFile(): extra block handling
		// =========================================================================

		public function testSkipsPackageWithoutExtraWithoutRaisingWarning(): void {
			$path = $this->writeJson([
				'packages' => [
					['name' => 'vendor/no-extra'],
					['name' => 'vendor/a', 'extra' => ['discover' => []]],
				],
			]);

			$warnings = [];
			set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
				$warnings[] = $errstr;
				return true;
			});

			try {
				$result = $this->parse($path);
			} finally {
				restore_error_handler();
			}

			$this->assertSame([], $warnings);
			$this->assertSame(['vendor/a' => ['discover' => []]], $result);
		}

		public function testSkipsPackageWithNonArrayExtra(): void {
			$path = $this->writeJson(['packages' => [['name' => 'vendor/a', 'extra' => 'not-an-object']]]);

			$this->assertSame([], $this->parse($path));
		}

		public function testSkipsPackageWithEmptyExtra(): void {
			$path = $this->writeJson(['packages' => [['name' => 'vendor/a', 'extra' => []]]]);

			$this->assertSame([], $this->parse($path));
		}

		public function testSkipsPackageWithListShapedExtra(): void {
			$path = $this->writeJson(['packages' => [['name' => 'vendor/a', 'extra' => ['x', 'y']]]]);

			$this->assertSame([], $this->parse($path));
		}

		public function testDropsIntegerKeysFromExtra(): void {
			// JSON object key "0" decodes to integer key 0 in PHP
			$path = $this->writeRaw('{"packages": [{"name": "vendor/a", "extra": {"0": "numeric", "discover": {"providers": ["A"]}}}]}');

			$this->assertSame(['vendor/a' => ['discover' => ['providers' => ['A']]]], $this->parse($path));
		}

		// =========================================================================
		// parseJsonFile(): malformed packages
		// =========================================================================

		public function testSkipsNonArrayPackageEntries(): void {
			$path = $this->writeJson([
				'packages' => [
					'not-a-package',
					['name' => 'vendor/a', 'extra' => ['discover' => []]],
				],
			]);

			$this->assertSame(['vendor/a' => ['discover' => []]], $this->parse($path));
		}

		public function testSkipsPackagesWithoutNameAndLogsWarning(): void {
			$path = $this->writeJson([
				'packages' => [
					['extra' => ['discover' => []]],
					['name' => 123, 'extra' => ['discover' => []]],
					['name' => 'vendor/a', 'extra' => ['discover' => []]],
				],
			]);

			$this->assertSame(['vendor/a' => ['discover' => []]], $this->parse($path));
			$this->assertCount(1, $this->logRecords);
			$this->assertSame('Packages missing name field', $this->logRecords[0]['message']);
			$this->assertSame(2, $this->logRecords[0]['context']['packages_without_name']);
			$this->assertSame(3, $this->logRecords[0]['context']['total_packages']);
		}

		// =========================================================================
		// parseJsonFile(): unreadable or invalid files
		// =========================================================================

		public function testReturnsEmptyArrayAndLogsWarningForMissingFile(): void {
			$this->assertSame([], $this->parse($this->tempDir . DIRECTORY_SEPARATOR . 'missing.json'));
			$this->assertSame('JSON file not readable', $this->logRecords[0]['message']);
		}

		public function testReturnsEmptyArrayAndLogsWarningForInvalidJson(): void {
			$path = $this->writeRaw('{"packages": [');

			$this->assertSame([], $this->parse($path));
			$this->assertSame('JSON parsing failed', $this->logRecords[0]['message']);
		}

		public function testReturnsEmptyArrayForNonArrayJson(): void {
			$path = $this->writeRaw('"just a string"');

			$this->assertSame([], $this->parse($path));
		}

		// =========================================================================
		// getData(): discovery mapping file
		// =========================================================================

		public function testGetDataReturnsDiscoveryMappingFileContents(): void {
			$mapping = ['vendor/a' => ['discover' => ['providers' => ['A']]]];

			file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'composer.json', '{}');
			mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'config');
			file_put_contents(
				$this->tempDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'discovery-mapping.php',
				'<?php return ' . var_export($mapping, true) . ';'
			);

			$loader = new ComposerInstalledLoader($this->tempDir, $this->createLogger());

			$this->assertSame($mapping, $loader->getData());
		}

		// =========================================================================
		// Helpers
		// =========================================================================

		/**
		 * Runs the protected parseJsonFile() on the given path.
		 * @param string $path
		 * @return array<string, array<string, mixed>>
		 */
		private function parse(string $path): array {
			$loader = new class($this->tempDir, $this->createLogger()) extends ComposerInstalledLoader {
				/**
				 * Exposes parseJsonFile() for testing.
				 * @param string $filePath
				 * @return array<string, array<string, mixed>>
				 */
				public function parse(string $filePath): array {
					return $this->parseJsonFile($filePath);
				}
			};

			return $loader->parse($path);
		}

		/**
		 * Creates a logger that records entries into $this->logRecords.
		 * @return AbstractLogger
		 */
		private function createLogger(): AbstractLogger {
			$records = &$this->logRecords;

			return new class($records) extends AbstractLogger {
				/** @var array<mixed> */
				private array $records;

				/**
				 * @param array<mixed> $records Array to append log entries to
				 */
				public function __construct(array &$records) {
					$this->records = &$records;
				}

				/**
				 * Records the log entry.
				 * @param mixed $level
				 * @param string|\Stringable $message
				 * @param array<mixed> $context
				 * @return void
				 */
				public function log($level, string|\Stringable $message, array $context = []): void {
					$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
				}
			};
		}

		/**
		 * Writes $data as JSON to a fixture file.
		 * @param array<mixed> $data
		 * @return string Path to the file
		 */
		private function writeJson(array $data): string {
			return $this->writeRaw((string)json_encode($data));
		}

		/**
		 * Writes raw content to a fixture file.
		 * @param string $content
		 * @return string Path to the file
		 */
		private function writeRaw(string $content): string {
			$path = $this->tempDir . DIRECTORY_SEPARATOR . 'installed.json';
			file_put_contents($path, $content);
			return $path;
		}

		/**
		 * Recursively deletes a directory.
		 * @param string $dir
		 * @return void
		 */
		private function removeDirectory(string $dir): void {
			if (!is_dir($dir)) {
				return;
			}

			foreach (scandir($dir) ?: [] as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}

				$path = $dir . DIRECTORY_SEPARATOR . $entry;
				is_dir($path) ? $this->removeDirectory($path) : unlink($path);
			}

			rmdir($dir);
		}
	}
