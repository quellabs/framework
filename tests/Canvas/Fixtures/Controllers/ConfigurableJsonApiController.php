<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Controllers;

	use Quellabs\Canvas\Controllers\JsonApiController;
	use Quellabs\Canvas\Controllers\WriteOperation;
	use Quellabs\Canvas\Tests\Fixtures\Serializers\StubJsonApiSerializer;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\ObjectQuel\Serialization\Serializers\JsonApiSerializer;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Test-only JsonApiController whose per-attribute write decision and
	 * authorization decision are plain public properties, so each test can
	 * configure exactly the scenario it needs without a new subclass.
	 * Also exposes the protected methods under test as public wrappers,
	 * since the tests live outside the class hierarchy.
	 */
	class ConfigurableJsonApiController extends JsonApiController {

		/** @var list<string> Fields denied for create, on top of the allow-by-default base */
		public array $deniedFieldsForCreate = [];

		/** @var list<string> Fields denied for update, on top of the allow-by-default base */
		public array $deniedFieldsForUpdate = [];

		/** @var \Closure(object, string, WriteOperation): bool|null Full override of isAttributeWritable() for scenarios the denylists above can't express */
		public ?\Closure $isAttributeWritableCallback = null;

		/** @var \Closure(Request, object, WriteOperation): ?JsonResponse|null */
		public ?\Closure $authorizeCallback = null;

		/** @var class-string */
		private string $entityClass;

		public function __construct(Container $container, string $entityClass) {
			$this->entityClass = $entityClass;
			parent::__construct($container);
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

		protected function isAttributeWritable(object $entity, string $key, WriteOperation $operation): bool {
			if ($this->isAttributeWritableCallback !== null) {
				return ($this->isAttributeWritableCallback)($entity, $key, $operation);
			}

			$denied = $operation === WriteOperation::Create ? $this->deniedFieldsForCreate : $this->deniedFieldsForUpdate;
			return !in_array($key, $denied, true);
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
