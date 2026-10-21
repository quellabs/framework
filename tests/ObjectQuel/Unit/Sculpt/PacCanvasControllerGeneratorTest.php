<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PacCanvasControllerGenerator;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\GenPacProductEntity;

	/**
	 * The generated controller's isAttributeWritable() stub allows everything
	 * by default and lists the entity's non-identifier properties as a review
	 * starting point — it never denies a field on its own.
	 */
	class PacCanvasControllerGeneratorTest extends TestCase {

		private function makeEntityStore(): EntityStore {
			$configuration = new Configuration();
			$configuration->setEntityPath(__DIR__ . '/../../Fixtures/Entities');
			$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\Entities');
			$configuration->setUseMetadataCache(false);
			return new EntityStore($configuration);
		}

		public function testGeneratedControllerAllowsEverythingByDefaultAndListsNonIdentifierProperties(): void {
			$generator = new PacCanvasControllerGenerator(GenPacProductEntity::class, $this->makeEntityStore());

			$code = $generator->create();

			$this->assertStringContainsString('protected function isAttributeWritable(object $entity, string $key, WriteOperation $operation): bool {', $code);
			$this->assertStringContainsString('return true;', $code);
			$this->assertStringContainsString('Non-identifier properties: name, role', $code);
		}
	}
