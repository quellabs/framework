<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\Serialization\Serializers\JsonApiSerializer;
	use Quellabs\ObjectQuel\Serialization\UrlBuilders\JsonApiUrlBuilder;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities\CkiOrderLineEntity;

	/**
	 * Regression coverage for JsonApiSerializer's composite-key handling:
	 *
	 * - The resource id for a composite-key entity is no longer an
	 *   underscore-joined string (ambiguous if a component itself contains
	 *   '_', and collapses every component to a string), but 'c1.' followed by
	 *   the unpadded base64url of a JSON array of ordered [propertyName, value]
	 *   pairs, with int/string components kept distinct.
	 * - serializeRelationships() previously drove an InverseOf lookup off a
	 *   single $identifierValue argument — the owner's first identifier
	 *   component only. For a composite-key owner this silently dropped every
	 *   component after the first from the dependent query's criteria.
	 */
	class JsonApiSerializerCompositeIdentityTest extends TestCase {

		public function testCompositeResourceIdEncodesEveryOrderedPairWithDistinctTypes(): void {
			/** @var EntityManager $realEntityManager */
			$realEntityManager = $GLOBALS['test_em'];

			$order = new CkiOrderEntity();
			$order->setTenantId(7);
			$order->setNumber('B-1');
			$order->setAmount(0);

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('getEntityStore')->willReturn($realEntityManager->getEntityStore());
			$entityManager->method('findBy')->willReturn([]);

			$serializer = new JsonApiSerializer($entityManager, new JsonApiUrlBuilder('https://example.test'));
			$id = $serializer->serialize($order)['data']['id'];

			self::assertStringStartsWith('c1.', $id);

			$decoded = base64_decode(strtr(substr($id, 3), '-_', '+/'), true);
			self::assertNotFalse($decoded);

			$pairs = json_decode($decoded, true);
			self::assertSame([['tenantId', 7], ['number', 'B-1']], $pairs);

			// base64url: no padding, and none of the URL-unsafe '+' / '/' characters.
			self::assertStringNotContainsString('=', $id);
			self::assertStringNotContainsString('+', $id);
			self::assertStringNotContainsString('/', $id);
		}

		public function testSingleKeyResourceIdIsUnchanged(): void {
			/** @var EntityManager $realEntityManager */
			$realEntityManager = $GLOBALS['test_em'];

			$line = new CkiOrderLineEntity();
			(new PropertyHandler())->set($line, 'id', 5);
			$line->setLineAmount(0);

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('getEntityStore')->willReturn($realEntityManager->getEntityStore());
			$entityManager->method('findBy')->willReturn([]);

			$serializer = new JsonApiSerializer($entityManager, new JsonApiUrlBuilder('https://example.test'));

			self::assertSame('5', $serializer->serialize($line)['data']['id']);
		}

		public function testInverseOfLookupMatchesEveryComponentOfACompositeOwnerNotJustTheFirst(): void {
			/** @var EntityManager $realEntityManager */
			$realEntityManager = $GLOBALS['test_em'];

			$order = new CkiOrderEntity();
			$order->setTenantId(7);
			$order->setNumber('B-1');
			$order->setAmount(0);

			$capturedCriteria = null;

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('getEntityStore')->willReturn($realEntityManager->getEntityStore());
			$entityManager->expects($this->once())
				->method('findBy')
				->with(
					CkiOrderLineEntity::class,
					$this->callback(function (array $criteria) use (&$capturedCriteria): bool {
						$capturedCriteria = $criteria;
						return true;
					})
				)
				->willReturn([]);

			$serializer = new JsonApiSerializer($entityManager, new JsonApiUrlBuilder('https://example.test'));
			$serializer->serializeRelationships($order);

			// Both components of the composite key, keyed by the dependent entity's
			// own local FK property names — a regression back to a single
			// $identifierValue would drop 'orderNumber' entirely.
			self::assertSame(['orderTenantId' => 7, 'orderNumber' => 'B-1'], $capturedCriteria);
		}
	}
