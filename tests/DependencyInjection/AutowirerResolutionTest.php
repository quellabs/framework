<?php

	namespace Quellabs\DependencyInjection\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\DependencyInjection\CanvasContainer;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\DependencyInjection\Autowiring\Autowirer;
	use Quellabs\DependencyInjection\Autowiring\MethodContext;
	use Quellabs\DependencyInjection\Exception\UnresolvableParameterException;
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Target type a provider can legitimately resolve to no value for, e.g. an
	 * entity with no matching database row.
	 */
	class AutowirerResolutionNullableTarget {
	}

	/**
	 * Provider that always reports "no result" for AutowirerResolutionNullableTarget,
	 * recording how many times it was actually asked.
	 */
	class AutowirerResolutionNullProvider extends AbstractProvider implements ServiceProviderInterface {
		public int $calls = 0;

		public static function getMetadata(): array {
			return [];
		}

		public function supports(string $className, array $metadata): bool {
			return $className === AutowirerResolutionNullableTarget::class;
		}

		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			$this->calls++;
			return null;
		}
	}

	/**
	 * Target type whose provider always throws, to verify the exception survives
	 * the Container/Autowirer call chain unchanged.
	 */
	class AutowirerResolutionFailingTarget {
	}

	class AutowirerResolutionFailingProvider extends AbstractProvider implements ServiceProviderInterface {
		public static function getMetadata(): array {
			return [];
		}

		public function supports(string $className, array $metadata): bool {
			return $className === AutowirerResolutionFailingTarget::class;
		}

		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			throw new \DomainException('autowirer-resolution-provider-failure');
		}
	}

	/**
	 * First union candidate: its own constructor parameter cannot be autowired
	 * (a required, un-provided scalar), so resolving it throws
	 * UnresolvableParameterException — a recoverable miss for this one candidate.
	 */
	class AutowirerResolutionCandidateA {
		public function __construct(string $missing) {
		}
	}

	/**
	 * Second union candidate: trivially constructible, so it is the one that
	 * should win once the first candidate fails.
	 */
	class AutowirerResolutionCandidateB {
	}

	class AutowirerResolutionCircularA {
		private AutowirerResolutionCircularB $b;

		public function __construct(AutowirerResolutionCircularB $b) {
			$this->b = $b;
		}
	}

	class AutowirerResolutionCircularB {
		private AutowirerResolutionCircularA $a;

		public function __construct(AutowirerResolutionCircularA $a) {
			$this->a = $a;
		}
	}

	class AutowirerResolutionPlainDependency {
	}

	class AutowirerResolutionPlainConsumer {
		private AutowirerResolutionPlainDependency $dependency;

		public function __construct(AutowirerResolutionPlainDependency $dependency) {
			$this->dependency = $dependency;
		}

		public function getDependency(): AutowirerResolutionPlainDependency {
			return $this->dependency;
		}
	}

	class AutowirerResolutionController {
		public function requiredTarget(AutowirerResolutionNullableTarget $target): ?AutowirerResolutionNullableTarget {
			return $target;
		}

		public function nullableTarget(?AutowirerResolutionNullableTarget $target): ?AutowirerResolutionNullableTarget {
			return $target;
		}

		public function failingTarget(AutowirerResolutionFailingTarget $target): AutowirerResolutionFailingTarget {
			return $target;
		}

		public function pickCandidate(AutowirerResolutionCandidateA|AutowirerResolutionCandidateB $candidate): AutowirerResolutionCandidateA|AutowirerResolutionCandidateB {
			return $candidate;
		}

		public function twoRequiredScalars(string $missing, string $alsoMissing): void {
		}
	}

	/**
	 * Unit tests for the base Autowirer/Container resolution-signal fix:
	 * a provider's null is a final, resolved answer (not "unresolved"), a
	 * provider's own exception survives the call chain unchanged, a known
	 * recoverable miss on one union candidate still falls through to the next,
	 * circular dependencies are still reported, and the current parameter name
	 * is reset even when resolution throws.
	 */
	class AutowirerResolutionTest extends TestCase {

		/**
		 * A real, working ContainerInterface implementation. The bare base
		 * Container cannot be used standalone here: its plain Discover cannot
		 * autowire the constructor-dependent providers already registered
		 * elsewhere in this monorepo (e.g. canvas-objectquel's ServiceProvider),
		 * so it crashes during discovery. CanvasContainer's DependencyAwareDiscover
		 * handles that; these tests otherwise exercise the base Autowirer/Container
		 * resolution logic directly, not anything Canvas-specific.
		 */
		private function makeContainer(): ContainerInterface {
			return new CanvasContainer(new AnnotationReader(new Configuration()));
		}

		public function testProviderResolvedNullIsInjectedIntoARequiredParameterInsteadOfThrowing(): void {
			$container = $this->makeContainer();
			$provider = new AutowirerResolutionNullProvider();
			$container->register($provider);

			$autowirer = new Autowirer($container);
			$controller = new AutowirerResolutionController();
			$args = $autowirer->getMethodArguments($controller, 'requiredTarget');

			$this->assertSame([null], $args);
			$this->assertSame(1, $provider->calls);
		}

		public function testProviderResolvedNullIsInjectedIntoANullableParameter(): void {
			$container = $this->makeContainer();
			$provider = new AutowirerResolutionNullProvider();
			$container->register($provider);

			$autowirer = new Autowirer($container);
			$controller = new AutowirerResolutionController();
			$args = $autowirer->getMethodArguments($controller, 'nullableTarget');

			$this->assertSame([null], $args);
			$this->assertSame(1, $provider->calls);
		}

		public function testProviderExceptionPropagatesUnchangedThroughContainerAndAutowirer(): void {
			$container = $this->makeContainer();
			$container->register(new AutowirerResolutionFailingProvider());

			$autowirer = new Autowirer($container);
			$controller = new AutowirerResolutionController();

			$this->expectException(\DomainException::class);
			$this->expectExceptionMessage('autowirer-resolution-provider-failure');
			$autowirer->getMethodArguments($controller, 'failingTarget');
		}

		public function testUnresolvableUnionCandidateFallsThroughToTheNextOne(): void {
			$container = $this->makeContainer();
			$autowirer = new Autowirer($container);
			$controller = new AutowirerResolutionController();

			$args = $autowirer->getMethodArguments($controller, 'pickCandidate');

			$this->assertCount(1, $args);
			$this->assertInstanceOf(AutowirerResolutionCandidateB::class, $args[0]);
		}

		public function testCircularDependencyIsStillReportedAndNotSwallowed(): void {
			$container = $this->makeContainer();

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/Circular dependency detected/');
			$container->make(AutowirerResolutionCircularA::class);
		}

		public function testOrdinaryNonEntityConstructorDependencyStillResolves(): void {
			$container = $this->makeContainer();

			$consumer = $container->make(AutowirerResolutionPlainConsumer::class);

			$this->assertInstanceOf(AutowirerResolutionPlainDependency::class, $consumer->getDependency());
		}

		public function testParameterNameIsResetAfterResolutionThrows(): void {
			$container = $this->makeContainer();
			$autowirer = new Autowirer($container);
			$controller = new AutowirerResolutionController();
			$context = new MethodContext(AutowirerResolutionController::class, 'twoRequiredScalars');

			try {
				$autowirer->getMethodArguments($controller, 'twoRequiredScalars', [], $context);
				$this->fail('Expected UnresolvableParameterException was not thrown');
			} catch (UnresolvableParameterException) {
				// Expected: neither scalar parameter has a provider, value, or default.
			}

			$this->assertNull($context->getParameterName());
		}
	}
