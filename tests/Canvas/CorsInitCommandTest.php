<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Sculpt\CorsInitCommand;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Tests cors:init's config file generation against a temporary project root,
	 * so the test suite never writes to this repository's own config directory.
	 */
	class CorsInitCommandTest extends TestCase {

		private string $originalCwd;
		private string $tempRoot;

		protected function setUp(): void {
			$this->originalCwd = getcwd();
			$this->tempRoot = sys_get_temp_dir() . '/canvas-cors-init-test-' . uniqid();
			mkdir($this->tempRoot, 0755, true);
			file_put_contents($this->tempRoot . '/composer.json', '{}');

			chdir($this->tempRoot);
			ComposerUtils::clearCache();
		}

		protected function tearDown(): void {
			chdir($this->originalCwd);
			ComposerUtils::clearCache();
			$this->removeDirectory($this->tempRoot);
		}

		private function removeDirectory(string $directory): void {
			if (!is_dir($directory)) {
				return;
			}

			foreach (scandir($directory) as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}

				$path = $directory . '/' . $entry;

				if (is_dir($path)) {
					$this->removeDirectory($path);
				} else {
					unlink($path);
				}
			}

			rmdir($directory);
		}

		private function makeCommand(): CorsInitCommand {
			return new CorsInitCommand(
				$this->createStub(ConsoleInput::class),
				$this->createStub(ConsoleOutput::class),
				$this->createStub(ProviderInterface::class)
			);
		}

		public function testSignatureIsRegisteredAsCorsInit(): void {
			$this->assertSame('cors:init', $this->makeCommand()->getSignature());
		}

		public function testCreatesTheConfigDirectoryAndFileWhenMissing(): void {
			$command = $this->makeCommand();

			$exitCode = $command->execute(new ConfigurationManager([]));

			$configPath = $this->tempRoot . '/config/cors.php';
			$this->assertSame(0, $exitCode);
			$this->assertFileExists($configPath);
		}

		public function testGeneratedFileIsValidPhpWithDenyByDefaultValues(): void {
			$command = $this->makeCommand();
			$command->execute(new ConfigurationManager([]));

			$config = require $this->tempRoot . '/config/cors.php';

			$this->assertSame([], $config['allowed_origins']);
			$this->assertSame([], $config['allowed_methods']);
			$this->assertSame([], $config['allowed_headers']);
			$this->assertSame([], $config['exposed_headers']);
			$this->assertFalse($config['allow_credentials']);
			$this->assertSame(0, $config['max_age']);
		}

		public function testRefusesToOverwriteAnExistingFileWithoutForce(): void {
			$command = $this->makeCommand();
			$command->execute(new ConfigurationManager([]));

			$configPath = $this->tempRoot . '/config/cors.php';
			file_put_contents($configPath, '<?php return ["marker" => true];');

			$exitCode = $command->execute(new ConfigurationManager([]));

			$this->assertSame(1, $exitCode);
			$this->assertTrue((require $configPath)['marker']);
		}

		public function testForceFlagOverwritesTheExistingFile(): void {
			$command = $this->makeCommand();
			$command->execute(new ConfigurationManager([]));

			$configPath = $this->tempRoot . '/config/cors.php';
			file_put_contents($configPath, '<?php return ["marker" => true];');

			$exitCode = $command->execute(new ConfigurationManager(['--force']));

			$this->assertSame(0, $exitCode);
			$this->assertArrayNotHasKey('marker', require $configPath);
		}

		public function testLeavesConfigAppPhpUnchanged(): void {
			mkdir($this->tempRoot . '/config');
			file_put_contents($this->tempRoot . '/config/app.php', '<?php return ["marker" => true];');

			$command = $this->makeCommand();
			$command->execute(new ConfigurationManager([]));

			$this->assertTrue((require $this->tempRoot . '/config/app.php')['marker']);
		}

		public function testAWriteFailureReturnsANonzeroExitCode(): void {
			// Create 'config' as a file instead of a directory, so writing
			// config/cors.php inside it is impossible.
			file_put_contents($this->tempRoot . '/config', 'not a directory');

			$command = $this->makeCommand();
			$exitCode = $command->execute(new ConfigurationManager([]));

			$this->assertSame(1, $exitCode);
		}
	}
