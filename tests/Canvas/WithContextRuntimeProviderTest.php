<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\DependencyInjection\CanvasAutowirer;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\Contracts\DependencyInjection\ContextProviderInterface;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * A plain service with no dependencies, used as the @WithContext-annotated
	 * parameter's type throughout these tests.
	 */
	class WithContextRuntimeProviderFakeService {
	}

	/**
	 * Resolves the context from the current method name, proving resolution
	 * happens at request time rather than being baked in at parse time.
	 */
	class WithContextRuntimeProviderFakeProvider implements ContextProviderInterface {
		public function resolve(?MethodContextInterface $methodContext): string {
			return 'resolved-for-' . ($methodContext?->getMethodName() ?? 'unknown');
		}
	}

	/**
	 * Records for()/get() calls across a FakeContainer and every clone for()
	 * produces, since the autowirer swaps in for()'s returned clone and the
	 * test needs to observe calls made on that clone, not the original.
	 */
	class WithContextRuntimeProviderCallRecorder {
		/** @var array<int, array<string, mixed>> */
		public array $forCalls = [];

		/** @var array<int, string> */
		public array $getCalls = [];
	}

	/**
	 * Minimal ContainerInterface test double that records every for() and get()
	 * call instead of performing real service discovery / provider routing.
	 */
	class WithContextRuntimeProviderFakeContainer implements ContainerInterface {

		private WithContextRuntimeProviderCallRecorder $recorder;

		public function __construct(?WithContextRuntimeProviderCallRecorder $recorder = null) {
			$this->recorder = $recorder ?? new WithContextRuntimeProviderCallRecorder();
		}

		public function recorder(): WithContextRuntimeProviderCallRecorder {
			return $this->recorder;
		}

		/**
		 * Not needed for this test
		 * @param ServiceProviderInterface $provider
		 * @return self
		 */
		public function register(ServiceProviderInterface $provider): self {
			return $this;
		}

		/**
		 * Not needed for this test
		 * @param ServiceProviderInterface $provider
		 * @return self
		 */
		public function unregister(ServiceProviderInterface $provider): self {
			return $this;
		}

		/**
		 * Records the context passed by CanvasAutowirer and returns a scoped clone
		 * @param string|array<string, mixed> $context
		 * @return self
		 */
		public function for(string|array $context): self {
			$this->recorder->forCalls[] = is_string($context) ? ['context' => $context] : $context;
			return clone $this;
		}

		/**
		 * Not needed for this test
		 * @param string $className
		 * @return ServiceProviderInterface
		 */
		public function findProvider(string $className): ServiceProviderInterface {
			throw new \RuntimeException('Not needed for this test');
		}

		/**
		 * Records the class fetched and returns a bare instance
		 * @param string $className
		 * @param array<string, mixed> $parameters
		 * @param MethodContextInterface|null $methodContext
		 * @return object|null
		 */
		public function get(string $className, array $parameters = [], ?MethodContextInterface $methodContext = null): ?object {
			$this->recorder->getCalls[] = $className;
			return new $className();
		}

		/**
		 * Not needed for this test
		 * @param string $className
		 * @param array<string, mixed> $parameters
		 * @return object
		 */
		public function make(string $className, array $parameters = []): object {
			return new $className();
		}

		/**
		 * Not needed for this test
		 * @param object $instance
		 * @param string $methodName
		 * @param array<string, mixed> $parameters
		 * @return mixed
		 */
		public function invoke(object $instance, string $methodName, array $parameters = []): mixed {
			return $instance->$methodName();
		}
	}

	/**
	 * Controller with a literal (non-provider) @WithContext tag.
	 */
	class WithContextRuntimeProviderLiteralController {
		/**
		 * @WithContext(parameter="service", context="redis")
		 */
		public function index(WithContextRuntimeProviderFakeService $service): void {
		}
	}

	/**
	 * Controller whose @WithContext context names a provider class, declared
	 * as a docblock annotation.
	 */
	class WithContextRuntimeProviderDocblockController {
		/**
		 * @WithContext(parameter="service", context=WithContextRuntimeProviderFakeProvider::class)
		 */
		public function index(WithContextRuntimeProviderFakeService $service): void {
		}
	}

	/**
	 * Controller whose @WithContext context names a provider class, declared
	 * as a native PHP attribute.
	 */
	class WithContextRuntimeProviderAttributeController {
		#[WithContext(parameter: 'service', context: WithContextRuntimeProviderFakeProvider::class)]
		public function index(WithContextRuntimeProviderFakeService $service): void {
		}
	}

	/**
	 * Controller with no @WithContext annotation at all.
	 */
	class WithContextRuntimeProviderPlainController {
		public function index(WithContextRuntimeProviderFakeService $service): void {
		}
	}

	/**
	 * Verifies that @WithContext resolves a provider class-string at request time
	 * while leaving literal context tags untouched, per
	 * with-context-runtime-provider-design.md.
	 */
	class WithContextRuntimeProviderTest extends TestCase {

		private function makeAutowirer(WithContextRuntimeProviderFakeContainer $container, string $source = Configuration::SOURCE_DOCBLOCKS): CanvasAutowirer {
			$configuration = new Configuration();
			$configuration->setSource($source);
			return new CanvasAutowirer($container, new AnnotationReader($configuration));
		}

		public function testLiteralContextTagPassesThroughUnchanged(): void {
			$container = new WithContextRuntimeProviderFakeContainer();
			$autowirer = $this->makeAutowirer($container);

			$autowirer->getMethodArguments(WithContextRuntimeProviderLiteralController::class, 'index');

			$this->assertSame(['context' => 'redis'], $container->recorder()->forCalls[0] ?? null);
		}

		public function testProviderClassStringIsResolvedAtRequestTimeDocblockForm(): void {
			$container = new WithContextRuntimeProviderFakeContainer();
			$autowirer = $this->makeAutowirer($container);

			$autowirer->getMethodArguments(WithContextRuntimeProviderDocblockController::class, 'index');

			// The provider itself must have been fetched from the (unscoped) container
			$this->assertContains(WithContextRuntimeProviderFakeProvider::class, $container->recorder()->getCalls);

			// for() must receive the provider's computed value, not the class-string
			$this->assertSame(['context' => 'resolved-for-index'], $container->recorder()->forCalls[0] ?? null);
		}

		public function testProviderClassStringIsResolvedAtRequestTimeAttributeForm(): void {
			$container = new WithContextRuntimeProviderFakeContainer();
			$autowirer = $this->makeAutowirer($container, Configuration::SOURCE_ATTRIBUTES);

			$autowirer->getMethodArguments(WithContextRuntimeProviderAttributeController::class, 'index');

			$this->assertSame(['context' => 'resolved-for-index'], $container->recorder()->forCalls[0] ?? null);
		}

		public function testNoWithContextAnnotationLeavesContainerUnscoped(): void {
			$container = new WithContextRuntimeProviderFakeContainer();
			$autowirer = $this->makeAutowirer($container);

			$autowirer->getMethodArguments(WithContextRuntimeProviderPlainController::class, 'index');

			$this->assertSame([], $container->recorder()->forCalls);
		}
	}
