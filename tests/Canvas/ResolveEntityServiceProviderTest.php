<?php

	namespace Quellabs\Canvas\Tests\ObjectQuel;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\Canvas\Kernel;
	use Quellabs\CanvasObjectQuel\Annotations\ResolveEntity;
	use Quellabs\CanvasObjectQuel\Exceptions\EntityBindingException;
	use Quellabs\CanvasObjectQuel\ServiceProvider;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Unit tests for ServiceProvider::createInstance().
	 *
	 * Covers the DI-provided parameter name as the resolution key, the implicit
	 * {parameterName} binding convention, @ResolveEntity annotation overrides matched
	 * by parameter name, and that a missing route key or unsupported value shape is a
	 * binding error (EntityBindingException) distinct from a valid lookup with no row
	 * (which returns null).
	 */
	class ResolveEntityServiceProviderTest extends TestCase {

		// =========================================================================
		// Helpers
		// =========================================================================

		/**
		 * Builds a ServiceProvider wired to a container that serves the given
		 * EntityManager and a Kernel whose AnnotationReader returns $annotations
		 * for any getMethodAnnotations() call.
		 * @param array<int, ResolveEntity> $annotations
		 */
		private function makeProvider(EntityManager $entityManager, array $annotations = []): ServiceProvider {
			$annotationReader = $this->createMock(AnnotationReader::class);
			$annotationReader->method('getMethodAnnotations')->willReturn(new AnnotationCollection($annotations));

			$kernel = $this->createMock(Kernel::class);
			$kernel->method('getAnnotationsReader')->willReturn($annotationReader);

			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				function (string $className) use ($kernel, $entityManager): ?object {
					return match ($className) {
						Kernel::class => $kernel,
						EntityManager::class => $entityManager,
						default => null,
					};
				}
			);

			return new ServiceProvider($container);
		}

		/**
		 * Builds a MethodContextInterface stub carrying the given autowired
		 * parameter name and route arguments.
		 * @param array<string, mixed> $arguments
		 */
		private function makeContext(?string $parameterName, array $arguments): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getClassName')->willReturn('App\\Controllers\\PostController');
			$context->method('getMethodName')->willReturn('show');
			$context->method('getArguments')->willReturn($arguments);
			$context->method('getParameterName')->willReturn($parameterName);
			return $context;
		}

		// =========================================================================
		// No method context
		// =========================================================================

		public function testReturnsNullWhenMethodContextIsNull(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->never())->method('find');

			$provider = $this->makeProvider($entityManager);

			$this->assertNull($provider->createInstance('App\\Entities\\PostEntity', [], [], null));
		}

		// =========================================================================
		// Implicit binding by parameter name
		// =========================================================================

		public function testResolvesByParameterNameWhenNoAnnotationPresent(): void {
			$entity = new \stdClass();

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('find')
				->with('App\\Entities\\PostEntity', 'hello-world')
				->willReturn($entity);

			$provider = $this->makeProvider($entityManager);
			$context = $this->makeContext('slug', ['slug' => 'hello-world']);

			$this->assertSame($entity, $provider->createInstance('App\\Entities\\PostEntity', [], [], $context));
		}

		public function testReturnsNullWhenParameterNameCannotBeDetermined(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->never())->method('find');

			$provider = $this->makeProvider($entityManager);
			$context = $this->makeContext(null, ['id' => 42]);

			$this->assertNull($provider->createInstance('App\\Entities\\PostEntity', [], [], $context));
		}

		public function testThrowsBindingExceptionWhenRouteArgumentIsMissing(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->never())->method('find');

			$provider = $this->makeProvider($entityManager);
			$context = $this->makeContext('post', ['id' => 42]);

			$this->expectException(EntityBindingException::class);
			$this->expectExceptionMessage("route has no value for key 'post'");
			$provider->createInstance('App\\Entities\\PostEntity', [], [], $context);
		}

		public function testThrowsBindingExceptionWhenRouteArgumentIsNotStringOrInt(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->never())->method('find');

			$provider = $this->makeProvider($entityManager);
			$context = $this->makeContext('post', ['post' => ['not' => 'scalar']]);

			$this->expectException(EntityBindingException::class);
			$this->expectExceptionMessage('composite-key binding is not supported');
			$provider->createInstance('App\\Entities\\PostEntity', [], [], $context);
		}

		// =========================================================================
		// Valid lookup, no row — the only case that legitimately returns null
		// =========================================================================

		public function testReturnsNullWhenLookupFindsNoRow(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('find')
				->with('App\\Entities\\PostEntity', 'missing-slug')
				->willReturn(null);

			$provider = $this->makeProvider($entityManager);
			$context = $this->makeContext('post', ['post' => 'missing-slug']);

			$this->assertNull($provider->createInstance('App\\Entities\\PostEntity', [], [], $context));
		}

		// =========================================================================
		// @ResolveEntity override
		// =========================================================================

		public function testAnnotationOverridesImplicitParameterNameBinding(): void {
			$entity = new \stdClass();

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('find')
				->with('App\\Entities\\PostEntity', 'hello-world')
				->willReturn($entity);

			$annotation = new ResolveEntity(parameter: 'post', routeParam: 'slug');
			$provider = $this->makeProvider($entityManager, [$annotation]);
			$context = $this->makeContext('post', ['slug' => 'hello-world', 'post' => 'ignored']);

			$this->assertSame($entity, $provider->createInstance('App\\Entities\\PostEntity', [], [], $context));
		}

		public function testAnnotationTargetingADifferentParameterIsIgnored(): void {
			$entity = new \stdClass();

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('find')
				->with('App\\Entities\\PostEntity', 'hello-world')
				->willReturn($entity);

			$annotation = new ResolveEntity(parameter: 'category', routeParam: 'categorySlug');
			$provider = $this->makeProvider($entityManager, [$annotation]);
			$context = $this->makeContext('post', ['post' => 'hello-world']);

			$this->assertSame($entity, $provider->createInstance('App\\Entities\\PostEntity', [], [], $context));
		}

		public function testMultipleAnnotationsDisambiguateMultipleParameters(): void {
			$entity = new \stdClass();

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('find')
				->with('App\\Entities\\CategoryEntity', 'tech')
				->willReturn($entity);

			$annotations = [
				new ResolveEntity(parameter: 'post', routeParam: 'slug'),
				new ResolveEntity(parameter: 'category', routeParam: 'categorySlug'),
			];

			$provider = $this->makeProvider($entityManager, $annotations);
			$context = $this->makeContext('category', ['slug' => 'hello-world', 'categorySlug' => 'tech']);

			$this->assertSame($entity, $provider->createInstance('App\\Entities\\CategoryEntity', [], [], $context));
		}
	}
