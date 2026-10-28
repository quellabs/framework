<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\DependencyInjection\CanvasAutowirer;
	use Quellabs\Canvas\DependencyInjection\CanvasContainer;
	use Quellabs\Canvas\Exceptions\RequiredParameterNotFoundException;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\EntityProviderInterface;
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Target type an EntityProviderInterface provider can legitimately resolve
	 * to no value for, e.g. an entity with no matching database row.
	 */
	class CanvasAutowirerRequiredParameterTarget {
	}

	class CanvasAutowirerRequiredParameterEntityProvider extends AbstractProvider implements EntityProviderInterface {
		public function supports(string $className, array $metadata): bool {
			return $className === CanvasAutowirerRequiredParameterTarget::class;
		}

		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			return null;
		}
	}

	/**
	 * Target type an ordinary (non-entity) provider resolves to no value for —
	 * a cache or logger backend being unavailable, say. Its null must stay a DI
	 * failure, never a 404.
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
	 * Unit tests for CanvasAutowirer's required-vs-nullable handling of a
	 * provider's null: a required (non-nullable) parameter resolved to null by
	 * an EntityProviderInterface provider becomes a deliberate
	 * RequiredParameterNotFoundException (HTTP 404), a nullable one still
	 * receives null, and — critically — null from an ordinary
	 * ServiceProviderInterface provider (not EntityProviderInterface) is left
	 * as an ordinary DI outcome, never turned into a 404. This decision lives
	 * here (Canvas), not in the base Autowirer or in canvas-objectquel's
	 * provider.
	 */
	class CanvasAutowirerRequiredParameterTest extends TestCase {

		private function makeAutowirer(): CanvasAutowirer {
			$container = new CanvasContainer(new AnnotationReader(new Configuration()));
			$container->register(new CanvasAutowirerRequiredParameterEntityProvider());
			$container->register(new CanvasAutowirerRequiredParameterOrdinaryProvider());
			return new CanvasAutowirer($container, new AnnotationReader(new Configuration()));
		}

		public function testRequiredEntityBoundParameterResolvedToNullThrows404(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$this->expectException(RequiredParameterNotFoundException::class);
			$this->expectExceptionCode(404);
			$autowirer->getMethodArguments($controller, 'required');
		}

		public function testNullableEntityBoundParameterResolvedToNullStaysNull(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			$args = $autowirer->getMethodArguments($controller, 'nullable');

			$this->assertSame([null], $args);
		}

		public function testRequiredParameterFromAnOrdinaryProviderIsNotConvertedTo404(): void {
			$autowirer = $this->makeAutowirer();
			$controller = new CanvasAutowirerRequiredParameterController();

			// Null from a non-entity provider must not become a 404; it stays
			// the ordinary resolved value (a real controller invocation would
			// then hit PHP's own TypeError, same as any other DI failure).
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
