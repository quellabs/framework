<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;

	/**
	 * The sample app's entities under src/Entities carry both @Orm docblock
	 * annotations and #[...] native attributes side by side (see
	 * CLAUDE.md-adjacent history: tests/bootstrap.php's shared EntityManager
	 * is hardcoded to docblocks, so the docblocks can't be removed, while
	 * config/app.php can point the real app at attributes instead). Nothing
	 * in the regular suite ever parses these files in attribute mode, so a
	 * wrong argument name or a copy-paste mistake in the attribute lines
	 * would go undetected.
	 *
	 * This test parses every entity class, property, and method with two
	 * independent AnnotationReader instances — one per source — and asserts
	 * the resulting annotation objects are equal. It never touches
	 * EntityStore or AnnotationReaderLocator, so it can't leak the
	 * attributes-mode reader into the rest of the suite.
	 */
	class AppEntitiesDocblockAttributeParityTest extends TestCase {

		/**
		 * @var list<class-string>
		 */
		private const ENTITY_CLASSES = [
			\App\Entities\AccountEntity::class,
			\App\Entities\CarEntity::class,
			\App\Entities\CredentialEntity::class,
			\App\Entities\DefaultColumnEntity::class,
			\App\Entities\NoKeyEntity::class,
			\App\Entities\PostEntity::class,
			\App\Entities\SequenceComponentEntity::class,
			\App\Entities\StringPkEntity::class,
			\App\Entities\UpsertConflictEntity::class,
			\App\Entities\UserEntity::class,
			\App\Entities\UuidVersionedEntity::class,
			\App\Entities\VehicleEntity::class,
			\App\Entities\VersionedEntity::class,
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
		public static function entityClassProvider(): iterable {
			foreach (self::ENTITY_CLASSES as $class) {
				yield $class => [$class];
			}
		}

		/**
		 * @dataProvider entityClassProvider
		 * @param class-string $class
		 * @return void
		 */
		public function testClassAnnotationsMatchBetweenDocblocksAndAttributes(string $class): void {
			$docblocks = $this->makeReader(AnnotationReaderConfiguration::SOURCE_DOCBLOCKS);
			$attributes = $this->makeReader(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$this->assertEquals(
				$docblocks->getClassAnnotations($class)->toIndexedArray(),
				$attributes->getClassAnnotations($class)->toIndexedArray(),
				"{$class}'s class-level attributes don't match its docblock annotations"
			);
		}

		/**
		 * @dataProvider entityClassProvider
		 * @param class-string $class
		 * @return void
		 */
		public function testPropertyAnnotationsMatchBetweenDocblocksAndAttributes(string $class): void {
			$docblocks = $this->makeReader(AnnotationReaderConfiguration::SOURCE_DOCBLOCKS);
			$attributes = $this->makeReader(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			// Guards against PHPUnit flagging the test as risky for a class with no properties
			$this->addToAssertionCount(1);

			foreach ((new \ReflectionClass($class))->getProperties() as $property) {
				$propertyName = $property->getName();

				$this->assertEquals(
					$docblocks->getPropertyAnnotations($class, $propertyName)->toIndexedArray(),
					$attributes->getPropertyAnnotations($class, $propertyName)->toIndexedArray(),
					"{$class}::\${$propertyName}'s attributes don't match its docblock annotations"
				);
			}
		}

		/**
		 * @dataProvider entityClassProvider
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
