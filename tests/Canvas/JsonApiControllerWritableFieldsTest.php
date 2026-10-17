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
	 * Tests JsonApiController's writable-field policy: the deny-by-default
	 * create/update allowlists, the hard identifier guard, the reflective
	 * write fallback for setter-less properties, and the resource-authorization
	 * hook. Uses a mocked EntityManager (metadata comes from a real EntityStore
	 * over the fixture entity; persist/flush/find are controlled per test) so
	 * these stay unit tests — nothing here touches a database.
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
		// validateAttributes(): allowed / unknown / forbidden
		// -------------------------------------------------------------------------

		public function testAllowedCreateFieldPassesValidation(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNull($response);
		}

		public function testUnknownAttributeIsSilentlyIgnoredRatherThanRejected(): void {
			$controller = $this->makeController();

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['doesNotExist' => 'x'], WriteOperation::Create);

			$this->assertNull($response);
		}

		public function testKnownButUnlistedAttributeIsRejectedWith403AndPerFieldPointer(): void {
			// 'role' has a real setter — stands in for a server-owned field the
			// controller simply never added to its allowlist. Deny-by-default
			// must protect it: a setter existing is not enough to make it writable.
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['role' => 'admin'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
			$payload = json_decode((string)$response->getContent(), true);
			$this->assertSame('/data/attributes/role', $payload['errors'][0]['source']['pointer']);
		}

		public function testForbiddenAttributeFailsEvenWhenAnUnknownOneIsAlsoPresent(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = ['name'];

			$response = $controller->callValidateAttributes(
				new TestProductEntity(),
				['role' => 'admin', 'doesNotExist' => 'x'],
				WriteOperation::Create
			);

			$this->assertSame(403, $response->getStatusCode());
		}

		public function testMixedAllowedAndForbiddenAttributesRejectsTheWholeRequest(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = ['name'];

			$response = $controller->callValidateAttributes(
				new TestProductEntity(),
				['name' => 'Widget', 'role' => 'admin'],
				WriteOperation::Create
			);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		// -------------------------------------------------------------------------
		// Identifiers: hard override, independent of what a controller declares
		// -------------------------------------------------------------------------

		public function testIdentifierAttributeIsForbiddenEvenWhenTheControllerListsIt(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = ['id']; // careless controller config

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
		// getWritableFields(): shared default for create and update
		// -------------------------------------------------------------------------

		public function testGetWritableFieldsForCreateDefaultsToTheSharedList(): void {
			$controller = $this->makeController();
			$controller->writableFields = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNull($response);
		}

		public function testGetWritableFieldsForUpdateDefaultsToTheSharedList(): void {
			$controller = $this->makeController();
			$controller->writableFields = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Update);

			$this->assertNull($response);
		}

		public function testOverridingWritableFieldsForCreateTakesPriorityOverTheSharedList(): void {
			$controller = $this->makeController();
			$controller->writableFields = ['name'];
			$controller->writableFieldsForCreate = []; // explicit override wins over the shared default

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		// -------------------------------------------------------------------------
		// Create vs update allowlists are independent
		// -------------------------------------------------------------------------

		public function testFieldWritableOnCreateIsNotAutomaticallyWritableOnUpdate(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = ['name'];
			$controller->writableFieldsForUpdate = [];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Update);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		public function testFieldWritableOnUpdateIsNotAutomaticallyWritableOnCreate(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = [];
			$controller->writableFieldsForUpdate = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		// -------------------------------------------------------------------------
		// No setter means no write — there is no reflective fallback. A field
		// that's genuinely unknown (not allowlisted, no setter) is silently
		// ignored, but a controller allowlisting a setter-less field is a
		// server-side misconfiguration and throws instead of silently no-op'ing.
		// -------------------------------------------------------------------------

		public function testUnallowlistedPropertyWithoutSetterIsSilentlyIgnored(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = []; // 'internalNotes' has no setter and isn't listed either
			$entity = new TestProductEntity();

			$validation = $controller->callValidateAttributes($entity, ['internalNotes' => 'secret'], WriteOperation::Create);
			$this->assertNull($validation);
		}

		public function testAllowlistingASetterLessPropertyThrowsAsAMisconfiguration(): void {
			$controller = $this->makeController();
			$controller->writableFieldsForCreate = ['internalNotes']; // allowlisted, but 'internalNotes' has no setter

			$this->expectException(\LogicException::class);
			$this->expectExceptionMessageMatches('/internalNotes.*getWritableFieldsForCreate.*setInternalNotes/');

			$controller->callValidateAttributes(new TestProductEntity(), ['internalNotes' => 'secret'], WriteOperation::Create);
		}

		// -------------------------------------------------------------------------
		// mapAttributesToEntity(): setter-preferred mechanism
		// -------------------------------------------------------------------------

		public function testMapAttributesToEntityUsesTheSetterWhenOneExists(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['name' => 'Widget']);

			$this->assertSame('Widget', $entity->getName());
		}

		public function testMapAttributesToEntityIgnoresAFieldWithoutASetter(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['internalNotes' => 'secret']);

			$this->assertNull($entity->getInternalNotes());
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
			$controller->writableFieldsForCreate = ['name'];
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
			$controller->writableFieldsForCreate = ['name'];
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

		public function testCreateResourceRejectsForbiddenAttributeWithoutPersisting(): void {
			[$controller, $mockEm] = $this->makeControllerWithMock();
			$controller->writableFieldsForCreate = ['name'];
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
			$controller->writableFieldsForUpdate = ['name'];
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
			$controller->writableFieldsForUpdate = ['name'];
			$mockEm->expects($this->once())->method('flush');

			$request = Request::create('/products/5', 'PUT', content: json_encode([
				'data' => ['type' => 'products', 'id' => '5', 'attributes' => ['name' => 'Widget', 'doesNotExist' => 'x']]
			]));

			$response = $controller->callUpdateResource(5, $request);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Widget', $entity->getName());
		}
	}
