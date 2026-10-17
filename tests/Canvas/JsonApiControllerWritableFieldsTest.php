<?php

	namespace Quellabs\Canvas\Tests\Controllers;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Controllers\WriteOperation;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Tests\Fixtures\Controllers\ConfigurableJsonApiController;
	use Quellabs\Canvas\Tests\Fixtures\Entities\TestProductEntity;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\DependencyInjection\Provider\SimpleBinding;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Tests JsonApiController's write policy: allow-by-default for any existing
	 * property, isAttributeWritable() as the sole write gate (independent of
	 * whether a setter exists), the hard primary-key guard, and the resource-
	 * authorization hook. Uses a mocked EntityManager (metadata comes from a
	 * real EntityStore over the fixture entity; persist/flush/find are
	 * controlled per test) so these stay unit tests — nothing here touches a
	 * database.
	 */
	class JsonApiControllerWritableFieldsTest extends TestCase {

		/**
		 * @param object|null $findResult What a mocked EntityManager::find() should return
		 */
		private function makeController(?object $findResult = null): ConfigurableJsonApiController {
			$configuration = new Configuration();
			$configuration->setEntityPath(__DIR__ . '/Fixtures/Entities');
			$configuration->setEntityNameSpace('Quellabs\\Canvas\\Tests\\Fixtures\\Entities');
			$configuration->setUseMetadataCache(false);
			$entityStore = new EntityStore($configuration);

			$mockEm = $this->createMock(EntityManager::class);
			$mockEm->method('getEntityStore')->willReturn($entityStore);
			$mockEm->method('persist')->willReturn(true);
			$mockEm->method('find')->willReturn($findResult);

			$container = $this->makeContainer($mockEm);

			return new ConfigurableJsonApiController($container, TestProductEntity::class);
		}

		/**
		 * Same as makeController(), but returns the mock too so a test can set
		 * call expectations (e.g. persist() must never be invoked).
		 * @return array{0: ConfigurableJsonApiController, 1: EntityManager&\PHPUnit\Framework\MockObject\MockObject}
		 */
		private function makeControllerWithMock(?object $findResult = null): array {
			$configuration = new Configuration();
			$configuration->setEntityPath(__DIR__ . '/Fixtures/Entities');
			$configuration->setEntityNameSpace('Quellabs\\Canvas\\Tests\\Fixtures\\Entities');
			$configuration->setUseMetadataCache(false);
			$entityStore = new EntityStore($configuration);

			$mockEm = $this->createMock(EntityManager::class);
			$mockEm->method('getEntityStore')->willReturn($entityStore);
			$mockEm->method('find')->willReturn($findResult);

			$container = $this->makeContainer($mockEm);
			$controller = new ConfigurableJsonApiController($container, TestProductEntity::class);

			return [$controller, $mockEm];
		}

		private function makeContainer(object $mockEm): Container {
			// A plain `new Container()` eagerly discovers every "di" provider declared
			// by this real app's installed packages, some of which need constructor
			// arguments the container can't supply outside normal Kernel bootstrap.
			// Kernel's own container uses an autowiring-aware discoverer that handles
			// that correctly, so it's reused here purely as a working DI container —
			// nothing Kernel-specific (routing, legacy, config) is exercised by these tests.
			$container = (new Kernel())->getDependencyInjector();
			$container->register(new SimpleBinding(EntityManager::class, $mockEm));
			return $container;
		}

		/**
		 * Build an entity as if the ORM had already hydrated it with an existing
		 * identifier — "id" has no setter by design, so this uses reflection the
		 * same way ORM hydration would, not the controller's own write path.
		 */
		private function entityWithId(int $id): TestProductEntity {
			$entity = new TestProductEntity();
			(new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
			return $entity;
		}

		// -------------------------------------------------------------------------
		// validateAttributes(): allow-by-default for any existing property
		// -------------------------------------------------------------------------

		public function testFieldWithSetterIsWritableByDefaultWithNoConfiguration(): void {
			$controller = $this->makeController();

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNull($response);
		}

		public function testUnknownAttributeIsSilentlyIgnored(): void {
			$controller = $this->makeController();

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['doesNotExist' => 'x'], WriteOperation::Create);

			$this->assertNull($response);
		}

		public function testDeniedFieldIsRejectedWith403AndPerFieldPointer(): void {
			// 'role' has a real setter but is explicitly denied here — isAttributeWritable()
			// is the only thing that can turn a setter-backed field into a hard 403.
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['role'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['role' => 'admin'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
			$payload = json_decode((string)$response->getContent(), true);
			$this->assertSame('/data/attributes/role', $payload['errors'][0]['source']['pointer']);
		}

		public function testDeniedAttributeFailsEvenWhenAnUnknownOneIsAlsoPresent(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['role'];

			$response = $controller->callValidateAttributes(
				new TestProductEntity(),
				['role' => 'admin', 'doesNotExist' => 'x'],
				WriteOperation::Create
			);

			$this->assertSame(403, $response->getStatusCode());
		}

		public function testMixedAllowedAndDeniedAttributesRejectsTheWholeRequest(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['role'];

			$response = $controller->callValidateAttributes(
				new TestProductEntity(),
				['name' => 'Widget', 'role' => 'admin'],
				WriteOperation::Create
			);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		// -------------------------------------------------------------------------
		// Identifiers: hard override, independent of isAttributeWritable()
		// -------------------------------------------------------------------------

		public function testIdentifierAttributeIsForbiddenEvenWhenIsAttributeWritableAllowsEverything(): void {
			$controller = $this->makeController();
			$controller->isAttributeWritableCallback = fn() => true; // careless controller config

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['id' => 999], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		public function testBodyIdMismatchIsStillRejectedBeforeAnyAttributeIsLookedAt(): void {
			[$controller, $mockEm] = $this->makeControllerWithMock($this->entityWithId(5));
			$mockEm->expects($this->never())->method('flush');

			$request = Request::create('/products/5', 'PUT', content: json_encode([
				'data' => ['type' => 'products', 'id' => '999', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callUpdateResource(5, $request);

			$this->assertSame(400, $response->getStatusCode());
		}

		// -------------------------------------------------------------------------
		// Create vs update denylists are independent
		// -------------------------------------------------------------------------

		public function testFieldDeniedOnCreateIsStillWritableOnUpdate(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Update);

			$this->assertNull($response);
		}

		public function testFieldDeniedOnUpdateIsStillWritableOnCreate(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForUpdate = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNull($response);
		}

		// -------------------------------------------------------------------------
		// Setter presence plays no role — isAttributeWritable() is the only gate
		// -------------------------------------------------------------------------

		public function testPropertyWithoutSetterIsWritableByDefaultViaReflection(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$response = $controller->callValidateAttributes($entity, ['internalNotes' => 'secret'], WriteOperation::Create);
			$this->assertNull($response);

			$controller->callMapAttributesToEntity($entity, ['internalNotes' => 'secret']);
			$this->assertSame('secret', $entity->getInternalNotes());
		}

		public function testPropertyWithoutSetterCanStillBeDeniedByIsAttributeWritable(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['internalNotes'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['internalNotes' => 'secret'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		// -------------------------------------------------------------------------
		// mapAttributesToEntity(): setter when present, reflection otherwise
		// -------------------------------------------------------------------------

		public function testMapAttributesToEntityUsesTheSetterWhenOneExists(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['name' => 'Widget']);

			$this->assertSame('Widget', $entity->getName());
		}

		public function testMapAttributesToEntityUsesReflectionWhenNoSetterExists(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['internalNotes' => 'secret']);

			$this->assertSame('secret', $entity->getInternalNotes());
		}

		public function testMapAttributesToEntityIgnoresAKeyThatIsNotAPropertyAtAll(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['doesNotExist' => 'x']);

			$this->assertFalse(property_exists($entity, 'doesNotExist'));
		}

		// -------------------------------------------------------------------------
		// Authorization hook
		// -------------------------------------------------------------------------

		public function testAuthorizationHookAllowsByDefault(): void {
			$controller = $this->makeController();

			$request = Request::create('/products', 'POST');
			$response = $this->invokeAuthorize($controller, $request, new TestProductEntity(), WriteOperation::Create);

			$this->assertNull($response);
		}

		public function testAuthorizationHookCanDenyBasedOnCurrentEntityState(): void {
			$controller = $this->makeController();
			$controller->authorizeCallback = function (Request $request, object $entity, WriteOperation $operation) {
				/** @var TestProductEntity $entity */
				return $entity->getId() === 5
					? new \Symfony\Component\HttpFoundation\JsonResponse(['errors' => [['status' => '403']]], 403)
					: null;
			};

			$deniedResponse = $this->invokeAuthorize($controller, Request::create('/products/5'), $this->entityWithId(5), WriteOperation::Update);
			$allowedResponse = $this->invokeAuthorize($controller, Request::create('/products/6'), $this->entityWithId(6), WriteOperation::Update);

			$this->assertNotNull($deniedResponse);
			$this->assertNull($allowedResponse);
		}

		public function testAuthorizationDenialShortCircuitsBeforeFieldValidation(): void {
			[$controller, $mockEm] = $this->makeControllerWithMock();
			$controller->authorizeCallback = fn() => new \Symfony\Component\HttpFoundation\JsonResponse(['errors' => [['status' => '403']]], 403);
			$mockEm->expects($this->never())->method('persist');

			// Even a fully valid, allowed attribute must not reach field validation.
			$request = Request::create('/products', 'POST', content: json_encode([
				'data' => ['type' => 'products', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callCreateResource($request);

			$this->assertSame(403, $response->getStatusCode());
		}

		/**
		 * authorizeAttributeWrite() is protected; call it the same way createResource()/
		 * updateResource() do, via reflection, since these tests live outside the hierarchy.
		 */
		private function invokeAuthorize(
			ConfigurableJsonApiController $controller,
			Request                       $request,
			object                        $entity,
			WriteOperation                $operation
		): ?\Symfony\Component\HttpFoundation\JsonResponse {
			$method = new \ReflectionMethod($controller, 'authorizeAttributeWrite');
			$method->setAccessible(true);
			return $method->invoke($controller, $request, $entity, $operation);
		}

		// -------------------------------------------------------------------------
		// Full createResource()/updateResource() flow
		// -------------------------------------------------------------------------

		public function testCreateResourceSucceedsAndPersistsTheMappedEntity(): void {
			[$controller, $mockEm] = $this->makeControllerWithMock();
			$mockEm->expects($this->once())
				->method('persist')
				->with($this->callback(fn(TestProductEntity $entity) => $entity->getName() === 'Widget'))
				->willReturn(true);
			$mockEm->expects($this->once())->method('flush');

			$request = Request::create('/products', 'POST', content: json_encode([
				'data' => ['type' => 'products', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callCreateResource($request);

			$this->assertSame(201, $response->getStatusCode());
		}

		public function testCreateResourceRejectsDeniedAttributeWithoutPersisting(): void {
			[$controller, $mockEm] = $this->makeControllerWithMock();
			$controller->deniedFieldsForCreate = ['role'];
			$mockEm->expects($this->never())->method('persist');

			$request = Request::create('/products', 'POST', content: json_encode([
				'data' => ['type' => 'products', 'attributes' => ['name' => 'Widget', 'role' => 'admin']]
			]));

			$response = $controller->callCreateResource($request);

			$this->assertSame(403, $response->getStatusCode());
		}

		public function testUpdateResourceSucceedsAndMapsOntoTheFetchedEntity(): void {
			$entity = $this->entityWithId(5);
			[$controller, $mockEm] = $this->makeControllerWithMock($entity);
			$mockEm->expects($this->once())->method('flush');

			$request = Request::create('/products/5', 'PUT', content: json_encode([
				'data' => ['type' => 'products', 'id' => '5', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callUpdateResource(5, $request);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Widget', $entity->getName());
		}

		public function testUpdateResourceIgnoresAnUnknownAttributeAndStillSucceeds(): void {
			$entity = $this->entityWithId(5);
			[$controller, $mockEm] = $this->makeControllerWithMock($entity);
			$mockEm->expects($this->once())->method('flush');

			$request = Request::create('/products/5', 'PUT', content: json_encode([
				'data' => ['type' => 'products', 'id' => '5', 'attributes' => ['name' => 'Widget', 'doesNotExist' => 'x']]
			]));

			$response = $controller->callUpdateResource(5, $request);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Widget', $entity->getName());
		}
	}
