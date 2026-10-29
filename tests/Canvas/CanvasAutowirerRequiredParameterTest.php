<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\DependencyInjection\CanvasAutowirer;
	use Quellabs\Canvas\DependencyInjection\CanvasContainer;
	use Quellabs\Canvas\Exceptions\RequiredParameterNotFoundException;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ResolutionNotFoundException;
	use Quellabs\DependencyInjection\Exception\UnresolvableParameterException;
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Target type a provider throws ResolutionNotFoundException for, e.g. an
	 * entity with no matching database row.
	 */
	class CanvasAutowirerRequiredParameterTarget {
	}

	/**
	 * Provider that always throws ResolutionNotFoundException for
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
		 * @return object|null Never returns; always throws
		 * @throws ResolutionNotFoundException Always
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			throw new ResolutionNotFoundException('no record for ' . $className);
		}
	}

	/**
	 * Target type an ordinary (non-entity) provider resolves to plain null for —
	 * a cache or logger backend being unavailable, say. Returning null rather
	 * than throwing ResolutionNotFoundException is exactly what keeps this a DI
	 * failure instead of a 404; nothing about the provider's own interface or
	 * class matters.
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
	 * (non-nullable) parameter whose provider threw ResolutionNotFoundException
	 * becomes a deliberate RequiredParameterNotFoundException (HTTP 404), a
	 * nullable one still receives null, and — critically — plain null from an
	 * ordinary provider (one that never throws ResolutionNotFoundException)
	 * still never becomes a 404; it is instead rejected as a generic
	 * UnresolvableParameterException (the base Autowirer's own guard against a
	 * required parameter silently resolving to null). The 404-vs-DI-failure
	 * distinction lives entirely in whether a provider throws that specific
	 * exception, not in which provider or interface it is. The 404 decision
	 * itself lives here (Canvas), not in the base Autowirer or in
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
			// must still be rejected as a clear DI failure rather than being
			// silently injected (which would only surface later as an opaque
			// PHP TypeError when the controller method is actually invoked).
			$this->expectException(UnresolvableParameterException::class);
			$this->expectExceptionMessageMatches('/resolved to null/');
			$autowirer->getMethodArguments($controller, 'requiredOrdinaryService');
		}

		public function testRequiredScalarParameterWithDefaultIsUnaffected(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$args = $autowirer->getMethodArguments($controller, 'requiredString');

			$this->assertSame(['default'], $args);
		}
	}
