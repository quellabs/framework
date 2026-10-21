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

		/**
		 * Skips the parent constructor; this stub never touches the ORM.
		 */
		public function __construct() {
		}

		/**
		 * Ignores the entity and returns a fixed, predictable JSON:API payload.
		 * @param object $entity
		 * @return array{data: array{type: string, id: string, attributes: array<string, mixed>, links: array{self: string}}}
		 */
		public function serialize(object $entity): array {
			return ['data' => ['type' => 'stub', 'id' => '0', 'attributes' => [], 'links' => ['self' => '/stub/0']]];
		}
	}
