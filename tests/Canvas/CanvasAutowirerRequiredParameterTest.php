<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\DependencyInjection\CanvasAutowirer;
	use Quellabs\Canvas\DependencyInjection\CanvasContainer;
	use Quellabs\Canvas\Exceptions\RequiredParameterNotFoundException;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\NotFound;
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Target type a provider resolves to NotFound::instance() for, e.g. an
	 * entity with no matching database row.
	 */
	class CanvasAutowirerRequiredParameterTarget {
	}

	class CanvasAutowirerRequiredParameterEntityProvider extends AbstractProvider implements ServiceProviderInterface {
		public function supports(string $className, array $metadata): bool {
			return $className === CanvasAutowirerRequiredParameterTarget::class;
		}

		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			return NotFound::instance();
		}
	}

	/**
	 * Target type an ordinary (non-entity) provider resolves to plain null for —
	 * a cache or logger backend being unavailable, say. Returning null rather
	 * than NotFound::instance() is exactly what keeps this a DI failure instead
	 * of a 404; nothing about the provider's own interface or class matters.
	 */
	class CanvasAutowirerRequiredParameterOrdinaryTarget {
	}

	class CanvasAutowirerRequiredParameterOrdinaryProvider extends AbstractProvider implements ServiceProviderInterface {
		public function supports(string $className, array $metadata): bool {
			return $className === CanvasAutowirerRequiredParameterOrdinaryTarget::class;
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

		public function requiredOrdinaryService(CanvasAutowirerRequiredParameterOrdinaryTarget $service): void {
		}

		public function requiredString(string $value = 'default'): void {
		}
	}

	/**
	 * Unit tests for CanvasAutowirer's handleNotFound() override: a required
	 * (non-nullable) parameter a provider resolved to NotFound::instance()
	 * becomes a deliberate RequiredParameterNotFoundException (HTTP 404), a
	 * nullable one still receives null, and — critically — plain null from an
	 * ordinary provider (not NotFound::instance()) is left as an ordinary DI
	 * outcome, never turned into a 404. The distinction lives entirely in which
	 * value a provider returns, not in which provider or interface produced it.
	 * This decision lives here (Canvas), not in the base Autowirer or in
	 * canvas-objectquel's provider.
	 */
	class CanvasAutowirerRequiredParameterTest extends TestCase {

		private function makeAutowirer(): CanvasAutowirer {
			$container = new CanvasContainer(new AnnotationReader(new Configuration()));
			$container->register(new CanvasAutowirerRequiredParameterEntityProvider());
			$container->register(new CanvasAutowirerRequiredParameterOrdinaryProvider());
			return new CanvasAutowirer($container, new AnnotationReader(new Configuration()));
		}

		public function testRequiredParameterResolvedToNotFoundThrows404(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$this->expectException(RequiredParameterNotFoundException::class);
			$this->expectExceptionCode(404);
			$autowirer->getMethodArguments($controller, 'required');
		}

		public function testNullableParameterResolvedToNotFoundStaysNull(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$args = $autowirer->getMethodArguments($controller, 'nullable');

			$this->assertSame([null], $args);
		}

		public function testRequiredParameterFromAnOrdinaryProviderIsNotConvertedTo404(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			// Plain null from an ordinary provider must not become a 404; it
			// stays the ordinary resolved value (a real controller invocation
			// would then hit PHP's own TypeError, same as any other DI failure).
			$args = $autowirer->getMethodArguments($controller, 'requiredOrdinaryService');

			$this->assertSame([null], $args);
		}

		public function testRequiredScalarParameterWithDefaultIsUnaffected(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$args = $autowirer->getMethodArguments($controller, 'requiredString');

			$this->assertSame(['default'], $args);
		}
	}
