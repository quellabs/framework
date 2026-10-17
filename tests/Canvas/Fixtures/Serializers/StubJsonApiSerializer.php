<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Serializers;

	use Quellabs\ObjectQuel\Serialization\Serializers\JsonApiSerializer;

	/**
	 * Trivial stand-in for JsonApiSerializer: these tests exercise
	 * JsonApiController's field-validation/authorization logic, not the ORM
	 * serialization pipeline, so the parent constructor is deliberately
	 * skipped and serialize() returns a fixed, predictable payload.
	 */
	class StubJsonApiSerializer extends JsonApiSerializer {

		public function __construct() {
		}

		public function serialize(object $entity): array {
			return ['data' => ['type' => 'stub', 'id' => '0', 'attributes' => []]];
		}
	}
