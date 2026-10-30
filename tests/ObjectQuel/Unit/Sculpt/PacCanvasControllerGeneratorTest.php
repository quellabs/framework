<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReaderLocator;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PacCanvasControllerGenerator;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\GenPacProductAttributeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\GenPacProductEntity;

	/**
	 * The generated controller's isAttributeWritable() stub allows everything
	 * by default and lists the entity's non-identifier properties as a review
	 * starting point — it never denies a field on its own. Also covers the
	 * annotation_source toggle: the generated controller must use @Route
	 * docblocks or #[Route] attributes to match EntityStore's configured source.
	 */
	class PacCanvasControllerGeneratorTest extends TestCase {

		/**
		 * Build an EntityStore reading fixture entities from the test fixtures directory.
		 * @param string $annotationSource Configuration::SOURCE_DOCBLOCKS or SOURCE_ATTRIBUTES
		 * @return EntityStore
		 */
		private function makeEntityStore(string $annotationSource = AnnotationReaderConfiguration::SOURCE_DOCBLOCKS): EntityStore {
			$configuration = new Configuration();
			$configuration->setEntityPath(__DIR__ . '/../../Fixtures/Entities');
			$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\Entities');
			$configuration->setUseMetadataCache(false);
			$configuration->setAnnotationSource($annotationSource);
			return new EntityStore($configuration);
		}

		/**
		 * The generated isAttributeWritable() stub allows everything and lists
		 * the entity's non-identifier properties as a review starting point.
		 * @return void
		 */
		public function testGeneratedControllerAllowsEverythingByDefaultAndListsNonIdentifierProperties(): void {
			$generator = new PacCanvasControllerGenerator(GenPacProductEntity::class, $this->makeEntityStore());

			$code = $generator->create();

			$this->assertStringContainsString('protected function isAttributeWritable(object $entity, string $key, WriteOperation $operation): bool {', $code);
			$this->assertStringContainsString('return true;', $code);
			$this->assertStringContainsString('Non-identifier properties: name, role', $code);
			$this->assertStringContainsString('@Route("/genpacproducts/{id}", methods={"GET"})', $code);
			$this->assertStringNotContainsString('#[Route(', $code);
		}

		/**
		 * With annotation_source=attributes, the generated controller must use
		 * native #[Route] attributes instead of @Route docblock annotations.
		 * @return void
		 */
		public function testGeneratedControllerUsesNativeRouteAttributesWhenSourceIsAttributes(): void {
			$previous = AnnotationReaderLocator::getInstance('objectquel');

			try {
				$store = $this->makeEntityStore(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);
				$generator = new PacCanvasControllerGenerator(GenPacProductAttributeEntity::class, $store);

				$code = $generator->create();

				$this->assertStringContainsString('#[Route("/genpacproductattributes/{id}", methods: ["GET"])]', $code);
				$this->assertStringContainsString('Non-identifier properties: name, role', $code);
				$this->assertStringNotContainsString('@Route(', $code);
			} finally {
				AnnotationReaderLocator::reset('objectquel');

				if ($previous !== null) {
					AnnotationReaderLocator::setInstance($previous, 'objectquel');
				}
			}
		}
	}
