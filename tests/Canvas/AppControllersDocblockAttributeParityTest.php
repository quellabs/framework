<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;

	/**
	 * The sample app's controllers under src/Controllers carry both @Route/
	 * @WithContext/@InterceptWith docblock annotations and #[...] native
	 * attributes side by side (config/app.php can point the real app at
	 * either source; nothing in the regular suite ever boots a real Kernel()
	 * against these files in attribute mode).
	 *
	 * This test parses every controller class and method with two
	 * independent AnnotationReader instances — one per source — and asserts
	 * the resulting annotation objects are equal, so a wrong argument name
	 * or value in the attribute lines can't silently diverge from the
	 * docblocks that back the current default.
	 */
	class AppControllersDocblockAttributeParityTest extends TestCase {

		/**
		 * @var list<class-string>
		 */
		private const CONTROLLER_CLASSES = [
			\App\Controllers\BlogController::class,
			\App\Controllers\HomeController::class,
		];

		/**
		 * @param string $source Configuration::SOURCE_DOCBLOCKS or SOURCE_ATTRIBUTES
		 * @return AnnotationReader
		 */
		private function makeReader(string $source): AnnotationReader {
			$config = new AnnotationReaderConfiguration();
			$config->setSource($source);
			return new AnnotationReader($config);
		}

		/**
		 * @return iterable<string, array{0: class-string}>
		 */
		public static function controllerClassProvider(): iterable {
			foreach (self::CONTROLLER_CLASSES as $class) {
				yield $class => [$class];
			}
		}

		/**
		 * @dataProvider controllerClassProvider
		 * @param class-string $class
		 * @return void
		 */
		public function testMethodAnnotationsMatchBetweenDocblocksAndAttributes(string $class): void {
			$docblocks = $this->makeReader(AnnotationReaderConfiguration::SOURCE_DOCBLOCKS);
			$attributes = $this->makeReader(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			// Guards against PHPUnit flagging the test as risky for a class with no methods
			$this->addToAssertionCount(1);

			foreach ((new \ReflectionClass($class))->getMethods() as $method) {
				$methodName = $method->getName();

				$this->assertEquals(
					$docblocks->getMethodAnnotations($class, $methodName)->toIndexedArray(),
					$attributes->getMethodAnnotations($class, $methodName)->toIndexedArray(),
					"{$class}::{$methodName}()'s attributes don't match its docblock annotations"
				);
			}
		}
	}
