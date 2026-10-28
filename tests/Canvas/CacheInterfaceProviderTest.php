<?php

declare(strict_types=1);

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\Canvas\Annotations\CacheContext;
	use Quellabs\Canvas\Cache\CacheInterfaceProvider;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;

	/**
	 * Fake MethodContext pointing CacheInterfaceProvider's resolveContext() at
	 * the fixture class/method below; the mocked AnnotationReader ignores the
	 * class/method identity and returns fixed annotations regardless.
	 */
	class CacheInterfaceProviderFakeMethodContext implements MethodContextInterface {
		private string $className;
		private string $methodName;

		public function __construct(string $className, string $methodName) {
			$this->className = $className;
			$this->methodName = $methodName;
		}

		public function getClassName(): string {
			return $this->className;
		}

		public function getMethodName(): string {
			return $this->methodName;
		}

		public function getArguments(): array {
			return [];
		}

		public function setParameterName(?string $name): void {
		}

		public function getParameterName(): ?string {
			return null;
		}
	}

	/**
	 * Fixture class that only needs to exist so class_exists() passes.
	 */
	class CacheInterfaceProviderFixture {
		public function index(): void {
		}
	}

	/**
	 * Verifies CacheInterfaceProvider's @CacheContext selection: a method may
	 * carry several @CacheContext instances, each tagged with the provider it
	 * configures; the one matching @WithContext's resolved context tag wins,
	 * falling back to the untagged default (if any), falling back to the
	 * dependencies passed into createInstance().
	 *
	 * Exercised via resolveContext() directly (not createInstance()), since
	 * createInstance() additionally resolves a concrete driver class through
	 * config/cache.php — unrelated to this selection logic.
	 */
	class CacheInterfaceProviderTest extends TestCase {

		/**
		 * @param array<int, CacheContext> $annotations
		 */
		private function makeProvider(array $annotations): CacheInterfaceProvider {
			$annotationReader = $this->createMock(AnnotationReader::class);
			$annotationReader->method('getMethodAnnotations')->willReturn(new AnnotationCollection($annotations));

			$container = $this->createMock(ContainerInterface::class);

			return new CacheInterfaceProvider($container, $annotationReader);
		}

		/**
		 * @param array<int|string, mixed> $dependencies
		 * @return array{namespace: mixed, params: array<int|string, mixed>, hash: string}
		 */
		private function resolveContext(
			CacheInterfaceProvider $provider,
			?MethodContextInterface $methodContext,
			array $dependencies,
			?string $resolvedContext
		): array {
			$method = new \ReflectionMethod($provider, 'resolveContext');
			$method->setAccessible(true);
			return $method->invoke($provider, $methodContext, $dependencies, $resolvedContext);
		}

		public function testNoAnnotationsDefaultsToDefaultNamespace(): void {
			$provider = $this->makeProvider([]);
			$methodContext = new CacheInterfaceProviderFakeMethodContext(CacheInterfaceProviderFixture::class, 'index');

			$context = $this->resolveContext($provider, $methodContext, [], 'redis');

			$this->assertSame('default', $context['namespace']);
		}

		public function testUntaggedCacheContextAppliesRegardlessOfResolvedContext(): void {
			$provider = $this->makeProvider([new CacheContext(namespace: 'sessions')]);
			$methodContext = new CacheInterfaceProviderFakeMethodContext(CacheInterfaceProviderFixture::class, 'index');

			$context = $this->resolveContext($provider, $methodContext, [], 'redis');

			$this->assertSame('sessions', $context['namespace']);
		}

		public function testTaggedCacheContextAppliesOnlyWhenItsContextMatchesResolvedContext(): void {
			$provider = $this->makeProvider([
				new CacheContext(context: 'redis', namespace: 'users-redis'),
				new CacheContext(context: 'memcached', namespace: 'users-memcached'),
			]);
			$methodContext = new CacheInterfaceProviderFakeMethodContext(CacheInterfaceProviderFixture::class, 'index');

			$context = $this->resolveContext($provider, $methodContext, [], 'memcached');

			$this->assertSame('users-memcached', $context['namespace']);
		}

		public function testTaggedCacheContextFallsBackToUntaggedDefaultWhenNoTagMatches(): void {
			$provider = $this->makeProvider([
				new CacheContext(context: 'redis', namespace: 'users-redis'),
				new CacheContext(namespace: 'users-default'),
			]);
			$methodContext = new CacheInterfaceProviderFakeMethodContext(CacheInterfaceProviderFixture::class, 'index');

			$context = $this->resolveContext($provider, $methodContext, [], 'memcached');

			$this->assertSame('users-default', $context['namespace']);
		}

		public function testNoMatchAndNoUntaggedDefaultFallsBackToDependencies(): void {
			$provider = $this->makeProvider([
				new CacheContext(context: 'redis', namespace: 'users-redis'),
			]);
			$methodContext = new CacheInterfaceProviderFakeMethodContext(CacheInterfaceProviderFixture::class, 'index');

			$context = $this->resolveContext($provider, $methodContext, ['namespace' => 'fallback'], 'memcached');

			$this->assertSame('fallback', $context['namespace']);
		}

		public function testNullMethodContextLeavesCacheContextUnresolved(): void {
			$provider = $this->makeProvider([]);

			$context = $this->resolveContext($provider, null, [], 'redis');

			$this->assertSame('default', $context['namespace']);
		}
	}
