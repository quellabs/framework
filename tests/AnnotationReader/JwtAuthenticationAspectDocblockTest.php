<?php

	declare(strict_types=1);

	namespace Quellabs\AnnotationReader\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\Security\JwtAuthenticationAspect;
	use Quellabs\Canvas\Security\StepUpAuthenticationAspect;

	/**
	 * Regression test for https://github.com/quellabs/framework/issues/434.
	 *
	 * JwtAuthenticationAspect's and StepUpAuthenticationAspect's own class
	 * docblocks used to embed literal "@InterceptWith(...)" usage examples.
	 * DocblockAnnotationReader parsed those as real annotations on the class
	 * itself and failed to resolve them, crashing with "Annotation class not
	 * found: InterceptWith" the first time either aspect was dispatched
	 * through @InterceptWith on a real route — tests that construct the
	 * aspect directly never exercised this path.
	 */
	class JwtAuthenticationAspectDocblockTest extends TestCase {

		private function reader(): AnnotationReader {
			$configuration = new Configuration();
			$configuration->setSource(Configuration::SOURCE_DOCBLOCKS);
			return new AnnotationReader($configuration);
		}

		public function testJwtAuthenticationAspectClassAnnotationsDoesNotThrow(): void {
			$annotations = $this->reader()->getClassAnnotations(JwtAuthenticationAspect::class);
			self::assertTrue($annotations->isEmpty());
		}

		public function testStepUpAuthenticationAspectClassAnnotationsDoesNotThrow(): void {
			$annotations = $this->reader()->getClassAnnotations(StepUpAuthenticationAspect::class);
			self::assertTrue($annotations->isEmpty());
		}
	}
