<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\DependencyInjection\CanvasAutowirer;
	use Quellabs\Canvas\DependencyInjection\CanvasContainer;
	use Quellabs\Canvas\Exceptions\RequiredParameterNotFoundException;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Target type a provider can legitimately resolve to no value for, e.g. an
	 * entity with no matching database row.
	 */
	class CanvasAutowirerRequiredParameterTarget {
	}

	class CanvasAutowirerRequiredParameterNullProvider extends AbstractProvider implements ServiceProviderInterface {
		public function supports(string $className, array $metadata): bool {
			return $className === CanvasAutowirerRequiredParameterTarget::class;
		}

		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			return null;
		}
	}

	class CanvasAutowirerRequiredParameterController {
		public function required(CanvasAutowirerRequiredParameterTarget $target): void {
		}

		public function nullable(?CanvasAutowirerRequiredParameterTarget $target): void {
		}

		public function requiredString(string $value = 'default'): void {
		}
	}

	/**
	 * Unit tests for CanvasAutowirer's required-vs-nullable handling of a
	 * provider's null: a required (non-nullable) object-typed parameter becomes
	 * a deliberate RequiredParameterNotFoundException (HTTP 404), while a
	 * nullable one still receives null — per the Canvas–ObjectQuel binding
	 * contract. This decision lives here (Canvas), not in the base Autowirer
	 * or in canvas-objectquel's provider.
	 */
	class CanvasAutowirerRequiredParameterTest extends TestCase {

		private function makeAutowirer(): CanvasAutowirer {
			$container = new CanvasContainer(new AnnotationReader(new Configuration()));
			$container->register(new CanvasAutowirerRequiredParameterNullProvider());
			return new CanvasAutowirer($container, new AnnotationReader(new Configuration()));
		}

		public function testRequiredObjectTypedParameterResolvedToNullThrows404(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$this->expectException(RequiredParameterNotFoundException::class);
			$this->expectExceptionCode(404);
			$autowirer->getMethodArguments($controller, 'required');
		}

		public function testNullableObjectTypedParameterResolvedToNullStaysNull(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$args = $autowirer->getMethodArguments($controller, 'nullable');

			$this->assertSame([null], $args);
		}

		public function testRequiredScalarParameterWithDefaultIsUnaffected(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$args = $autowirer->getMethodArguments($controller, 'requiredString');

			$this->assertSame(['default'], $args);
		}
	}
