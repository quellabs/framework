<?php

	namespace Quellabs\DependencyInjection\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\DependencyInjection\CanvasContainer;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\DependencyInjection\Autowiring\Autowirer;
	use Quellabs\DependencyInjection\Exception\UnresolvableParameterException;
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Interfaces used to build an intersection type (A&B) for the tests below.
	 */
	interface AutowirerTypeCheckInterfaceA {
	}

	interface AutowirerTypeCheckInterfaceB {
	}

	/**
	 * Satisfies both interfaces of the intersection.
	 */
	class AutowirerTypeCheckBoth implements AutowirerTypeCheckInterfaceA, AutowirerTypeCheckInterfaceB {
	}

	/**
	 * Satisfies only one member of the intersection - used to prove a
	 * provider's result is validated against every intersection member, not
	 * just the one it was asked to resolve.
	 */
	class AutowirerTypeCheckOnlyA implements AutowirerTypeCheckInterfaceA {
	}

	/**
	 * Trivially constructible class used for the explicit "Type|null" union
	 * and the mismatched-scalar-union tests.
	 */
	class AutowirerTypeCheckSimple {
	}

	/**
	 * Concrete class whose constructor can never be autowired, used to prove
	 * an invalid direct value for a class-typed parameter falls through to DI
	 * (which also fails) rather than being silently accepted.
	 */
	class AutowirerTypeCheckUnbuildable {

		/**
		 * @param string $required A required scalar nothing can supply, by design
		 */
		public function __construct(string $required) {
		}
	}

	/**
	 * Provider that always resolves AutowirerTypeCheckInterfaceA to a
	 * caller-supplied instance, regardless of whether that instance also
	 * satisfies AutowirerTypeCheckInterfaceB.
	 */
	class AutowirerTypeCheckInterfaceAProvider extends AbstractProvider implements ServiceProviderInterface {

		private object $instance;

		public function __construct(object $instance) {
			$this->instance = $instance;
		}

		/**
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === AutowirerTypeCheckInterfaceA::class;
		}

		/**
		 * @param string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			return $this->instance;
		}
	}

	/**
	 * Controller-shaped fixture whose methods exercise each type-validation
	 * scenario under test: intersection types, mixed class/scalar unions,
	 * explicit nullable unions, and invalid direct values.
	 */
	class AutowirerTypeCheckController {

		/**
		 * @param AutowirerTypeCheckInterfaceA&AutowirerTypeCheckInterfaceB $value
		 * @return object
		 */
		public function intersection(AutowirerTypeCheckInterfaceA&AutowirerTypeCheckInterfaceB $value): object {
			return $value;
		}

		/**
		 * @param int|AutowirerTypeCheckSimple $value
		 * @return int|AutowirerTypeCheckSimple
		 */
		public function mixedUnion(int|AutowirerTypeCheckSimple $value): int|AutowirerTypeCheckSimple {
			return $value;
		}

		/**
		 * @param AutowirerTypeCheckSimple|null $value
		 * @return object|null
		 */
		public function explicitNullUnion(AutowirerTypeCheckSimple|null $value): ?object {
			return $value;
		}

		/**
		 * @param AutowirerTypeCheckUnbuildable $value
		 * @return object
		 */
		public function invalidDirectValue(AutowirerTypeCheckUnbuildable $value): object {
			return $value;
		}
	}

	/**
	 * Unit tests for the Autowirer type-handling fix: union and intersection
	 * types are now kept distinct (a flat list previously let an intersection
	 * accept a value satisfying only one member), and a value - whether
	 * supplied directly or returned by a provider - is validated against the
	 * actual declared type rather than being waved through because the type
	 * list merely contains a built-in entry somewhere.
	 */
	class AutowirerTypeValidationTest extends TestCase {

		/**
		 * A real, working ContainerInterface implementation - see
		 * AutowirerResolutionTest for why the bare base Container cannot be
		 * used standalone here.
		 * @return ContainerInterface
		 */
		private function makeContainer(): ContainerInterface {
			return new CanvasContainer(new AnnotationReader(new Configuration()));
		}

		public function testIntersectionTypeAcceptsAProviderResultSatisfyingBothMembers(): void {
			$container = $this->makeContainer();
			$both = new AutowirerTypeCheckBoth();
			$container->register(new AutowirerTypeCheckInterfaceAProvider($both));

			$autowirer = new Autowirer($container);
			$controller = new AutowirerTypeCheckController();
			$args = $autowirer->getMethodArguments($controller, 'intersection');

			$this->assertSame([$both], $args);
		}

		public function testIntersectionTypeRejectsAProviderResultSatisfyingOnlyOneMember(): void {
			$container = $this->makeContainer();
			$container->register(new AutowirerTypeCheckInterfaceAProvider(new AutowirerTypeCheckOnlyA()));

			$autowirer = new Autowirer($container);
			$controller = new AutowirerTypeCheckController();

			// The result only implements InterfaceA, not the whole intersection.
			$this->expectException(UnresolvableParameterException::class);
			$autowirer->getMethodArguments($controller, 'intersection');
		}

		public function testMixedClassScalarUnionAcceptsAMatchingScalarDirectly(): void {
			$container = $this->makeContainer();
			$autowirer = new Autowirer($container);
			$controller = new AutowirerTypeCheckController();

			$args = $autowirer->getMethodArguments($controller, 'mixedUnion', ['value' => 42]);

			// The int branch is satisfied directly - no DI resolution needed.
			$this->assertSame([42], $args);
		}

		public function testMixedClassScalarUnionRejectsAMismatchedScalarAndFallsThroughToTheClassBranch(): void {
			$container = $this->makeContainer();
			$autowirer = new Autowirer($container);
			$controller = new AutowirerTypeCheckController();

			// Must fall through to DI and build a real AutowirerTypeCheckSimple,
			// not be waved through because 'int' merely appears in the types.
			$args = $autowirer->getMethodArguments($controller, 'mixedUnion', ['value' => 'not-an-int']);

			$this->assertCount(1, $args);
			$this->assertInstanceOf(AutowirerTypeCheckSimple::class, $args[0]);
		}

		public function testExplicitNullUnionAcceptsAMatchingInstanceDirectly(): void {
			$container = $this->makeContainer();
			$autowirer = new Autowirer($container);
			$controller = new AutowirerTypeCheckController();
			$instance = new AutowirerTypeCheckSimple();

			$args = $autowirer->getMethodArguments($controller, 'explicitNullUnion', ['value' => $instance]);

			// assertSame (not just instanceof) proves this came from the
			// directly-supplied value, not a freshly DI-constructed one.
			$this->assertSame([$instance], $args);
		}

		public function testInvalidDirectValueForAClassTypedParameterIsRejected(): void {
			$container = $this->makeContainer();
			$autowirer = new Autowirer($container);
			$controller = new AutowirerTypeCheckController();

			// DI also fails here (the class's own constructor can't autowire),
			// so this must fail clearly rather than passing the string through.
			$this->expectException(UnresolvableParameterException::class);
			$autowirer->getMethodArguments($controller, 'invalidDirectValue', ['value' => 'not-an-instance']);
		}
	}
