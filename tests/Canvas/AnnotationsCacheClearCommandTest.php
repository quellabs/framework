<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\Sculpt\AnnotationsCacheClearCommand;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Tests annotations:clear-cache against a reader on a temporary cache
	 * directory, so the suite never touches this repository's own cache.
	 */
	class AnnotationsCacheClearCommandTest extends TestCase {

		private string $cacheDirectory;

		/**
		 * Creates an empty temporary cache directory.
		 * @return void
		 */
		protected function setUp(): void {
			$this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'annotations-clear-cache-' . bin2hex(random_bytes(8));
			mkdir($this->cacheDirectory, 0755, true);
		}

		/**
		 * Removes the temporary cache directory.
		 * @return void
		 */
		protected function tearDown(): void {
			foreach (glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
				unlink($file);
			}

			rmdir($this->cacheDirectory);
		}

		/**
		 * The command, reading through a docblock reader on the temporary directory.
		 * @param ConsoleOutput $output
		 * @return AnnotationsCacheClearCommand
		 */
		private function makeCommand(ConsoleOutput $output): AnnotationsCacheClearCommand {
			$configuration = new Configuration();
			$configuration->setSource(Configuration::SOURCE_DOCBLOCKS);
			$configuration->setUseAnnotationCache(true);
			$configuration->setAnnotationCachePath($this->cacheDirectory);
			$reader = new AnnotationReader($configuration);

			return new class($this->createStub(ConsoleInput::class), $output, $this->createStub(ProviderInterface::class), $reader) extends AnnotationsCacheClearCommand {
				private AnnotationReader $testReader;

				/**
				 * @param ConsoleInput $input
				 * @param ConsoleOutput $output
				 * @param ProviderInterface $provider
				 * @param AnnotationReader $reader
				 */
				public function __construct(ConsoleInput $input, ConsoleOutput $output, ProviderInterface $provider, AnnotationReader $reader) {
					parent::__construct($input, $output, $provider);
					$this->testReader = $reader;
				}

				/**
				 * @return AnnotationReader
				 */
				protected function annotationReader(): AnnotationReader {
					return $this->testReader;
				}
			};
		}

		/**
		 * Writes a file into the temporary cache directory.
		 * @param string $name
		 * @return string The file's path
		 */
		private function cacheFile(string $name): string {
			$path = $this->cacheDirectory . DIRECTORY_SEPARATOR . $name;
			file_put_contents($path, '{}');

			return $path;
		}

		/**
		 * @return void
		 */
		public function testSignatureIsAnnotationsClearCache(): void {
			$this->assertSame('annotations:clear-cache', $this->makeCommand($this->createStub(ConsoleOutput::class))->getSignature());
		}

		/**
		 * @return void
		 */
		public function testRemovesTheReadersCacheFilesAndLeavesOthersAlone(): void {
			$controller = $this->cacheFile('App#Controllers#BillingController.cache');
			$entity = $this->cacheFile('App#Entities#InvoiceEntity.cache');
			$attributeCache = $this->cacheFile('App#Controllers#BillingController.attributes.cache');
			$unrelated = $this->cacheFile('README.txt');

			$output = $this->createMock(ConsoleOutput::class);
			$output->expects($this->once())->method('success')->with('Annotation cache cleared');

			$exitCode = $this->makeCommand($output)->execute(new ConfigurationManager([]));

			$this->assertSame(0, $exitCode);
			$this->assertFileDoesNotExist($controller);
			$this->assertFileDoesNotExist($entity);
			$this->assertFileExists($attributeCache, 'A native-attribute cache belongs to the other reader.');
			$this->assertFileExists($unrelated);
		}

		/**
		 * @return void
		 */
		public function testSucceedsWhenThereIsNothingToClear(): void {
			$output = $this->createMock(ConsoleOutput::class);
			$output->expects($this->once())->method('success');

			$this->assertSame(0, $this->makeCommand($output)->execute(new ConfigurationManager([])));
		}
	}
