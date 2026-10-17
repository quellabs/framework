<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Controllers;

	use Quellabs\Canvas\Controllers\JsonApiController;
	use Quellabs\Canvas\Controllers\WriteOperation;
	use Quellabs\Canvas\Tests\Fixtures\Serializers\StubJsonApiSerializer;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\Serialization\Serializers\JsonApiSerializer;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Test-only JsonApiController whose writable-field policy and
	 * authorization decision are plain public properties, so each test can
	 * configure exactly the scenario it needs without a new subclass.
	 * Also exposes the protected methods under test as public wrappers,
	 * since the tests live outside the class hierarchy.
	 */
	class ConfigurableJsonApiController extends JsonApiController {

		/** @var list<string>|null Shared default used by both operations when the per-operation list below is null */
		public ?array $writableFields = null;

		/** @var list<string>|null Null means "fall back to getWritableFields()", same as the base class */
		public ?array $writableFieldsForCreate = null;

		/** @var list<string>|null Null means "fall back to getWritableFields()", same as the base class */
		public ?array $writableFieldsForUpdate = null;

		/** @var \Closure(Request, object, WriteOperation): ?JsonResponse|null */
		public ?\Closure $authorizeCallback = null;

		/** @var class-string */
		private string $entityClass;

		public function __construct(Container $container, PropertyHandler $propertyHandler, string $entityClass) {
			$this->entityClass = $entityClass;
			parent::__construct($container, $propertyHandler);
		}

		protected function createSerializer(): JsonApiSerializer {
			return new StubJsonApiSerializer();
		}

		protected function getEntityClass(): string {
			return $this->entityClass;
		}

		protected function getResourceType(): string {
			return 'products';
		}

		protected function getWritableFields(): array {
			return $this->writableFields ?? [];
		}

		protected function getWritableFieldsForCreate(): array {
			return $this->writableFieldsForCreate ?? parent::getWritableFieldsForCreate();
		}

		protected function getWritableFieldsForUpdate(): array {
			return $this->writableFieldsForUpdate ?? parent::getWritableFieldsForUpdate();
		}

		protected function authorizeAttributeWrite(Request $request, object $entity, WriteOperation $operation): ?JsonResponse {
			return $this->authorizeCallback !== null ? ($this->authorizeCallback)($request, $entity, $operation) : null;
		}

		public function callValidateAttributes(object $entity, array $attributes, WriteOperation $operation): ?JsonResponse {
			return $this->validateAttributes($entity, $attributes, $operation);
		}

		public function callMapAttributesToEntity(object $entity, array $attributes): void {
			$this->mapAttributesToEntity($entity, $attributes);
		}

		public function callCreateResource(Request $request): JsonResponse {
			return $this->createResource($request);
		}

		public function callUpdateResource(int $id, Request $request): JsonResponse {
			return $this->updateResource($id, $request);
		}
	}
