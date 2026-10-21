<?php

	namespace Quellabs\Canvas\Tests\Controllers;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Controllers\WriteOperation;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Tests\Fixtures\Controllers\ConfigurableJsonApiController;
	use Quellabs\Canvas\Tests\Fixtures\Controllers\ControllerWithMock;
	use Quellabs\Canvas\Tests\Fixtures\Entities\TestProductEntity;
	use Quellabs\Canvas\Tests\Fixtures\Entities\TestProductWithAcronymSettersEntity;
	use Quellabs\Canvas\Tests\Fixtures\Entities\TestProductWithIdSetterEntity;
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
		 * @param class-string $entityClass The entity class the controller operates on
		 */
		private function makeController(?object $findResult = null, string $entityClass = TestProductEntity::class): ConfigurableJsonApiController {
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

			return new ConfigurableJsonApiController($container, $entityClass);
		}

		/**
		 * Same as makeController(), but returns the mock too so a test can set
		 * call expectations (e.g. persist() must never be invoked).
		 * @param object|null $findResult What a mocked EntityManager::find() should return
		 * @return ControllerWithMock
		 */
		private function makeControllerWithMock(?object $findResult = null): ControllerWithMock {
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

			return new ControllerWithMock($controller, $mockEm);
		}

		/**
		 * @param object $mockEm
		 * @return Container
		 */
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
		 * @param int $id
		 * @return TestProductEntity
		 */
		private function entityWithId(int $id): TestProductEntity {
			$entity = new TestProductEntity();
			(new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
			return $entity;
		}

		/**
		 * Decode a JsonResponse body into an array, failing the test with a clear
		 * message if the body isn't the JSON object these responses always are.
		 * @param \Symfony\Component\HttpFoundation\JsonResponse $response
		 * @return array<array-key, mixed>
		 */
		private function decodeJsonBody(\Symfony\Component\HttpFoundation\JsonResponse $response): array {
			$payload = json_decode((string)$response->getContent(), true);

			if (!is_array($payload)) {
				$this->fail('Expected the response body to decode to a JSON object.');
			}

			return $payload;
		}

		/**
		 * Extract the `source.pointer` of one error object from a JSON:API error
		 * response body, failing the test with a clear message if the shape
		 * doesn't match.
		 * @param array<array-key, mixed> $payload
		 * @param int $index
		 * @return string
		 */
		private function errorPointer(array $payload, int $index): string {
			$errors = $payload['errors'] ?? null;

			if (!is_array($errors) || !isset($errors[$index]) || !is_array($errors[$index])) {
				$this->fail('Expected the response body to contain an errors array.');
			}

			$source = $errors[$index]['source'] ?? null;

			if (!is_array($source) || !isset($source['pointer']) || !is_string($source['pointer'])) {
				$this->fail('Expected the error object to contain a string source.pointer.');
			}

			return $source['pointer'];
		}

		/**
		 * Encode a JSON:API request body, throwing instead of silently passing
		 * through a `false` on encode failure.
		 * @param array<mixed> $data
		 * @return string
		 */
		private function jsonBody(array $data): string {
			return json_encode($data, JSON_THROW_ON_ERROR);
		}

		// -------------------------------------------------------------------------
		// validateAttributes(): allow-by-default for any existing property
		// -------------------------------------------------------------------------

		/**
		 * A setter-backed field is writable by default when the concrete
		 * controller configures no denylist and no isAttributeWritable() override.
		 * @return void
		 */
		public function testFieldWithSetterIsWritableByDefaultWithNoConfiguration(): void {
			$controller = $this->makeController();

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNull($response);
		}

		/**
		 * A key that isn't a property on the entity at all, through a setter or
		 * directly, is rejected with 422 rather than silently ignored.
		 * @return void
		 */
		public function testUnknownAttributeIsRejectedWith422(): void {
			$controller = $this->makeController();

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['doesNotExist' => 'x'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(422, $response->getStatusCode());
		}

		/**
		 * 'role' has a real setter but is explicitly denied here — isAttributeWritable()
		 * is the only thing that can turn a setter-backed field into a hard 403.
		 * @return void
		 */
		public function testDeniedFieldIsRejectedWith403AndPerFieldPointer(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['role'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['role' => 'admin'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
			$payload = $this->decodeJsonBody($response);
			$this->assertSame('/data/attributes/role', $this->errorPointer($payload, 0));
		}

		/**
		 * Unknown attributes are rejected before isAttributeWritable() is ever
		 * consulted, so a request mixing the two gets the 422, not the 403.
		 * @return void
		 */
		public function testUnknownAttributeTakesPrecedenceOverADeniedOne(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['role'];

			$response = $controller->callValidateAttributes(
				new TestProductEntity(),
				['role' => 'admin', 'doesNotExist' => 'x'],
				WriteOperation::Create
			);

			$this->assertNotNull($response);
			$this->assertSame(422, $response->getStatusCode());
		}

		/**
		 * A request mixing an allowed and a denied attribute is rejected as a
		 * whole, not partially mapped.
		 * @return void
		 */
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

		/**
		 * The primary key is always forbidden, even when a careless controller's
		 * isAttributeWritable() override allows everything.
		 * @return void
		 */
		public function testIdentifierAttributeIsForbiddenEvenWhenIsAttributeWritableAllowsEverything(): void {
			$controller = $this->makeController();
			$controller->isAttributeWritableCallback = fn() => true; // careless controller config

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['id' => 999], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		/**
		 * PHP resolves method calls case-insensitively, so a naive lookup of
		 * "set" . ucfirst('ID') would find setId() while a strict name
		 * comparison against the identifier property ('id') would not. The
		 * identifier guard must resolve the key to its canonical property
		 * name before deciding, not compare the raw request key.
		 * @return void
		 */
		public function testDifferentlyCasedIdentifierKeyIsStillForbiddenWhenTheEntityHasAnIdSetter(): void {
			$controller = $this->makeController(entityClass: TestProductWithIdSetterEntity::class);
			$controller->isAttributeWritableCallback = fn() => true; // careless controller config

			$response = $controller->callValidateAttributes(new TestProductWithIdSetterEntity(), ['ID' => 999], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		/**
		 * Same as above, but with a setter capitalized as an acronym (setID()).
		 * Unmangling the setter name itself (lcfirst(substr('setID', 3)) === 'iD')
		 * would miss the identifier property ('id'); resolving against the
		 * entity's actual declared properties must not.
		 * @return void
		 */
		public function testAcronymCapitalizedSetterIdentifierKeyIsStillForbidden(): void {
			$controller = $this->makeController(entityClass: TestProductWithAcronymSettersEntity::class);
			$controller->isAttributeWritableCallback = fn() => true; // careless controller config

			$response = $controller->callValidateAttributes(new TestProductWithAcronymSettersEntity(), ['ID' => 999], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		/**
		 * Same unmangling flaw, now against isAttributeWritable() rather than the
		 * identifier guard: a denylist keyed on the real property name ('role')
		 * must still catch a request using a differently-cased key ('ROLE')
		 * against a setROLE() setter.
		 * @return void
		 */
		public function testAcronymCapitalizedSetterWritePolicyDenialIsStillEnforced(): void {
			$controller = $this->makeController(entityClass: TestProductWithAcronymSettersEntity::class);
			$controller->deniedFieldsForCreate = ['role'];

			$response = $controller->callValidateAttributes(new TestProductWithAcronymSettersEntity(), ['ROLE' => 'admin'], WriteOperation::Create);

			$this->assertNotNull($response);
			$this->assertSame(403, $response->getStatusCode());
		}

		/**
		 * A request body `id` that disagrees with the URL's resource ID is
		 * rejected before any attribute is validated or mapped.
		 * @return void
		 */
		public function testBodyIdMismatchIsStillRejectedBeforeAnyAttributeIsLookedAt(): void {
			$built = $this->makeControllerWithMock($this->entityWithId(5));
			$controller = $built->getController();
			$built->getMockEm()->expects($this->never())->method('flush');

			$request = Request::create('/products/5', 'PUT', content: $this->jsonBody([
				'data' => ['type' => 'products', 'id' => '999', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callUpdateResource(5, $request);

			$this->assertSame(400, $response->getStatusCode());
		}

		// -------------------------------------------------------------------------
		// Create vs update denylists are independent
		// -------------------------------------------------------------------------

		/**
		 * The create and update denylists are independent: a field denied for
		 * create is still writable on update.
		 * @return void
		 */
		public function testFieldDeniedOnCreateIsStillWritableOnUpdate(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForCreate = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Update);

			$this->assertNull($response);
		}

		/**
		 * Symmetric to the above: a field denied for update is still writable
		 * on create.
		 * @return void
		 */
		public function testFieldDeniedOnUpdateIsStillWritableOnCreate(): void {
			$controller = $this->makeController();
			$controller->deniedFieldsForUpdate = ['name'];

			$response = $controller->callValidateAttributes(new TestProductEntity(), ['name' => 'Widget'], WriteOperation::Create);

			$this->assertNull($response);
		}

		// -------------------------------------------------------------------------
		// Setter presence plays no role — isAttributeWritable() is the only gate
		// -------------------------------------------------------------------------

		/**
		 * A property with no setter is still writable by default, via the
		 * reflection-based mapping path — setter presence plays no role.
		 * @return void
		 */
		public function testPropertyWithoutSetterIsWritableByDefaultViaReflection(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$response = $controller->callValidateAttributes($entity, ['internalNotes' => 'secret'], WriteOperation::Create);
			$this->assertNull($response);

			$controller->callMapAttributesToEntity($entity, ['internalNotes' => 'secret']);
			$this->assertSame('secret', $entity->getInternalNotes());
		}

		/**
		 * A property with no setter can still be denied by isAttributeWritable(),
		 * confirming it (not setter presence) is the sole write gate.
		 * @return void
		 */
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

		/**
		 * mapAttributesToEntity() uses the setter when one exists for the key.
		 * @return void
		 */
		public function testMapAttributesToEntityUsesTheSetterWhenOneExists(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['name' => 'Widget']);

			$this->assertSame('Widget', $entity->getName());
		}

		/**
		 * mapAttributesToEntity() falls back to direct reflection when the key
		 * has no setter.
		 * @return void
		 */
		public function testMapAttributesToEntityUsesReflectionWhenNoSetterExists(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['internalNotes' => 'secret']);

			$this->assertSame('secret', $entity->getInternalNotes());
		}

		/**
		 * mapAttributesToEntity() is a no-op for a key that isn't a property on
		 * the entity at all, through a setter or directly.
		 * @return void
		 */
		public function testMapAttributesToEntityIgnoresAKeyThatIsNotAPropertyAtAll(): void {
			$controller = $this->makeController();
			$entity = new TestProductEntity();

			$controller->callMapAttributesToEntity($entity, ['doesNotExist' => 'x']);

			$this->assertArrayNotHasKey('doesNotExist', get_object_vars($entity));
		}

		// -------------------------------------------------------------------------
		// Authorization hook
		// -------------------------------------------------------------------------

		/**
		 * authorizeAttributeWrite() allows every create/update by default.
		 * @return void
		 */
		public function testAuthorizationHookAllowsByDefault(): void {
			$controller = $this->makeController();

			$request = Request::create('/products', 'POST');
			$response = $this->invokeAuthorize($controller, $request, new TestProductEntity(), WriteOperation::Create);

			$this->assertNull($response);
		}

		/**
		 * authorizeAttributeWrite() can deny based on the current entity's state
		 * (e.g. ownership), independent of which attributes were submitted.
		 * @return void
		 */
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

		/**
		 * A denial from authorizeAttributeWrite() short-circuits before field
		 * validation: even a fully valid, allowed attribute must not reach it.
		 * @return void
		 */
		public function testAuthorizationDenialShortCircuitsBeforeFieldValidation(): void {
			$built = $this->makeControllerWithMock();
			$controller = $built->getController();
			$controller->authorizeCallback = fn() => new \Symfony\Component\HttpFoundation\JsonResponse(['errors' => [['status' => '403']]], 403);
			$built->getMockEm()->expects($this->never())->method('persist');

			$request = Request::create('/products', 'POST', content: $this->jsonBody([
				'data' => ['type' => 'products', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callCreateResource($request);

			$this->assertSame(403, $response->getStatusCode());
		}

		/**
		 * authorizeAttributeWrite() is protected; call it the same way createResource()/
		 * updateResource() do, via reflection, since these tests live outside the hierarchy.
		 * @param ConfigurableJsonApiController $controller
		 * @param Request $request
		 * @param object $entity
		 * @param WriteOperation $operation
		 * @return \Symfony\Component\HttpFoundation\JsonResponse|null
		 */
		private function invokeAuthorize(
			ConfigurableJsonApiController $controller,
			Request                       $request,
			object                        $entity,
			WriteOperation                $operation
		): ?\Symfony\Component\HttpFoundation\JsonResponse {
			$method = new \ReflectionMethod($controller, 'authorizeAttributeWrite');
			$method->setAccessible(true);
			$result = $method->invoke($controller, $request, $entity, $operation);

			if ($result !== null && !$result instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
				$this->fail('authorizeAttributeWrite() returned an unexpected type.');
			}

			return $result;
		}

		// -------------------------------------------------------------------------
		// Full createResource()/updateResource() flow
		// -------------------------------------------------------------------------

		/**
		 * createResource() maps the request's attributes onto a new entity and
		 * persists it on success.
		 * @return void
		 */
		public function testCreateResourceSucceedsAndPersistsTheMappedEntity(): void {
			$built = $this->makeControllerWithMock();
			$controller = $built->getController();
			$built->getMockEm()->expects($this->once())
				->method('persist')
				->with($this->callback(fn(TestProductEntity $entity) => $entity->getName() === 'Widget'))
				->willReturn(true);
			$built->getMockEm()->expects($this->once())->method('flush');

			$request = Request::create('/products', 'POST', content: $this->jsonBody([
				'data' => ['type' => 'products', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callCreateResource($request);

			$this->assertSame(201, $response->getStatusCode());
		}

		/**
		 * createResource() rejects a denied attribute with 403 and never
		 * persists the entity.
		 * @return void
		 */
		public function testCreateResourceRejectsDeniedAttributeWithoutPersisting(): void {
			$built = $this->makeControllerWithMock();
			$controller = $built->getController();
			$controller->deniedFieldsForCreate = ['role'];
			$built->getMockEm()->expects($this->never())->method('persist');

			$request = Request::create('/products', 'POST', content: $this->jsonBody([
				'data' => ['type' => 'products', 'attributes' => ['name' => 'Widget', 'role' => 'admin']]
			]));

			$response = $controller->callCreateResource($request);

			$this->assertSame(403, $response->getStatusCode());
		}

		/**
		 * updateResource() maps the request's attributes onto the fetched
		 * entity and flushes on success.
		 * @return void
		 */
		public function testUpdateResourceSucceedsAndMapsOntoTheFetchedEntity(): void {
			$entity = $this->entityWithId(5);
			$built = $this->makeControllerWithMock($entity);
			$controller = $built->getController();
			$built->getMockEm()->expects($this->once())->method('flush');

			$request = Request::create('/products/5', 'PUT', content: $this->jsonBody([
				'data' => ['type' => 'products', 'id' => '5', 'attributes' => ['name' => 'Widget']]
			]));

			$response = $controller->callUpdateResource(5, $request);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Widget', $entity->getName());
		}

		/**
		 * updateResource() rejects an unknown attribute with 422 and never
		 * flushes the entity.
		 * @return void
		 */
		public function testUpdateResourceRejectsAnUnknownAttributeWithoutFlushing(): void {
			$entity = $this->entityWithId(5);
			$built = $this->makeControllerWithMock($entity);
			$controller = $built->getController();
			$built->getMockEm()->expects($this->never())->method('flush');

			$request = Request::create('/products/5', 'PUT', content: $this->jsonBody([
				'data' => ['type' => 'products', 'id' => '5', 'attributes' => ['name' => 'Widget', 'doesNotExist' => 'x']]
			]));

			$response = $controller->callUpdateResource(5, $request);

			$this->assertSame(422, $response->getStatusCode());
			$this->assertSame('', $entity->getName());
		}
	}
