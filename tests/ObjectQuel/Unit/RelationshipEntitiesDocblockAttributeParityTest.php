<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelAuditLogEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftChildWithOwnDeleteEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelBoolSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelCategoryEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelDepartmentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelDivBundleItemEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelDivCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelDivOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelDivOrderItemEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelDivProductEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelEmployeeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelFriendUserEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelFriendshipEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelOrderCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelOrderNoCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPlainChildOfSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPlainParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostTagEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileNoCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileUnidirectionalEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileUnidirectionalPersistEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftChildOfPlainParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftChildOfSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelSoftParentEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelTagEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelUserEntity;

	/**
	 * tests/ObjectQuel/Fixtures/RelationshipEntities carry both @Orm docblock
	 * annotations and #[...] native attributes side by side, the same as
	 * App\Entities — needed so tests/bootstrap.php's shared EntityManager
	 * (which loads both src/Entities and this directory) can run in
	 * attribute mode without this directory's fixtures going dark.
	 *
	 * This test parses every class, property, and method here with two
	 * independent AnnotationReader instances — one per source — and asserts
	 * the resulting annotation objects are equal.
	 */
	class RelationshipEntitiesDocblockAttributeParityTest extends TestCase {

		/**
		 * @var list<class-string>
		 */
		private const ENTITY_CLASSES = [
			RelAuditLogEntity::class,
			RelBoolSoftChildEntity::class,
			RelBoolSoftChildWithOwnDeleteEntity::class,
			RelBoolSoftParentEntity::class,
			RelCategoryEntity::class,
			RelCustomerEntity::class,
			RelDepartmentEntity::class,
			RelDivBundleItemEntity::class,
			RelDivCustomerEntity::class,
			RelDivOrderEntity::class,
			RelDivOrderItemEntity::class,
			RelDivProductEntity::class,
			RelEmployeeEntity::class,
			RelFriendUserEntity::class,
			RelFriendshipEntity::class,
			RelOrderCascadeEntity::class,
			RelOrderNoCascadeEntity::class,
			RelPlainChildOfSoftParentEntity::class,
			RelPlainParentEntity::class,
			RelPostEntity::class,
			RelPostTagEntity::class,
			RelProfileEntity::class,
			RelProfileNoCascadeEntity::class,
			RelProfileUnidirectionalEntity::class,
			RelProfileUnidirectionalPersistEntity::class,
			RelSoftChildOfPlainParentEntity::class,
			RelSoftChildOfSoftParentEntity::class,
			RelSoftParentEntity::class,
			RelTagEntity::class,
			RelUserEntity::class,
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
