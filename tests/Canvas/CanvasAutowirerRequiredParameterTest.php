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

	/**
	 * Provider that always reports NotFound::instance() for
	 * CanvasAutowirerRequiredParameterTarget.
	 */
	class CanvasAutowirerRequiredParameterEntityProvider extends AbstractProvider implements ServiceProviderInterface {

		/**
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === CanvasAutowirerRequiredParameterTarget::class;
		}

		/**
		 * @param string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return NotFound Always the shared NotFound sentinel
		 */
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

	/**
	 * Provider that always reports plain null for
	 * CanvasAutowirerRequiredParameterOrdinaryTarget.
	 */
	class CanvasAutowirerRequiredParameterOrdinaryProvider extends AbstractProvider implements ServiceProviderInterface {

		/**
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === CanvasAutowirerRequiredParameterOrdinaryTarget::class;
		}

		/**
		 * @param string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object|null Always null
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			return null;
		}
	}

	/**
	 * Controller-shaped fixture whose methods exercise each required/nullable
	 * resolution scenario under test.
	 */
	class CanvasAutowirerRequiredParameterController {

		/**
		 * @param CanvasAutowirerRequiredParameterTarget $target
		 * @return void
		 */
		public function required(CanvasAutowirerRequiredParameterTarget $target): void {
		}

		/**
		 * @param CanvasAutowirerRequiredParameterTarget|null $target
		 * @return void
		 */
		public function nullable(?CanvasAutowirerRequiredParameterTarget $target): void {
		}

		/**
		 * @param CanvasAutowirerRequiredParameterOrdinaryTarget $service
		 * @return void
		 */
		public function requiredOrdinaryService(CanvasAutowirerRequiredParameterOrdinaryTarget $service): void {
		}

		/**
		 * @param string $value
		 * @return void
		 */
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

		/**
		 * @return CanvasAutowirer
		 */
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
