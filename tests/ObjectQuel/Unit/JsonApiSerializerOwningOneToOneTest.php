<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\Serialization\Serializers\JsonApiSerializer;
	use Quellabs\ObjectQuel\Serialization\UrlBuilders\JsonApiUrlBuilder;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelUserEntity;

	/**
	 * Regression test for serializing an owning OneToOne relationship: the lookup
	 * must use the local FK column's value (RelProfileEntity::$userId), not the
	 * owning entity's own identifier. The two values are deliberately different
	 * here so a regression back to using the owner's own id cannot hide behind a
	 * coincidental match.
	 */
	class JsonApiSerializerOwningOneToOneTest extends TestCase {

		public function testOwningOneToOneLooksUpTargetByLocalForeignKeyValue(): void {
			/** @var EntityManager $realEntityManager */
			$realEntityManager = $GLOBALS['test_em'];

			$propertyHandler = new PropertyHandler();
			$profile = new RelProfileEntity();
			$propertyHandler->set($profile, 'id', 1);
			$propertyHandler->set($profile, 'userId', 42);

			$capturedCriteria = null;

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('getEntityStore')->willReturn($realEntityManager->getEntityStore());
			$entityManager->expects($this->once())
				->method('findBy')
				->with(
					RelUserEntity::class,
					$this->callback(function (array $criteria) use (&$capturedCriteria): bool {
						$capturedCriteria = $criteria;
						return true;
					})
				)
				->willReturn([]);

			$serializer = new JsonApiSerializer($entityManager, new JsonApiUrlBuilder('https://example.test'));

			// $identifierValue (1) is the profile's own PK. The FK column (userId = 42)
			// is what must drive the RelUserEntity lookup.
			$serializer->serializeRelationships($profile, 1);

			$this->assertSame(['id' => 42], $capturedCriteria);
		}
	}
