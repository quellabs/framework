<?php

	namespace Quellabs\CanvasEloquent\Tests;

	use Illuminate\Database\Eloquent\Model;
	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\CanvasEloquent\Annotations\ResolveModel;
	use Quellabs\CanvasEloquent\Exceptions\ModelBindingException;
	use Quellabs\CanvasEloquent\ModelBindingServiceProvider;
	use Quellabs\CanvasEloquent\ServiceProvider;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\Contracts\DependencyInjection\ResolutionNotFoundException;

	/**
	 * Eloquent model fixture for ResolveModelServiceProviderTest. A real,
	 * SQLite-backed model is used (rather than mocking an entity manager, as
	 * canvas-objectquel's equivalent test does) because Model::find() is a
	 * static call the provider invokes directly on the model class — there
	 * is no injectable manager to mock here.
	 */
	class ResolveModelFixturePost extends Model {

		/** @var string */
		protected $table = 'resolve_model_fixture_posts';

		/** @var array<int, string> */
		protected $fillable = ['id', 'title'];

		/** @var bool */
		public $timestamps = false;
	}

	/**
	 * Unit tests for ModelBindingServiceProvider::createInstance().
	 *
	 * Covers the DI-provided parameter name as the resolution key, the implicit
	 * {parameterName} binding convention, @ResolveModel annotation overrides matched
	 * by parameter name, and that a missing route key or unsupported value shape is a
	 * binding error (ModelBindingException) distinct from a valid lookup with no row
	 * (which throws ResolutionNotFoundException, not plain null). The fixture's
	 * connection is booted separately via ServiceProvider, mirroring how Discover
	 * registers both providers side by side in a real application.
	 */
	class ResolveModelServiceProviderTest extends TestCase {

		// =========================================================================
		// Helpers
		// =========================================================================

		/**
		 * Resets the provider's cached Capsule and Eloquent's static connection
		 * resolver before/after each test, since both are process-wide static
		 * state that would otherwise leak between tests.
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();
			$this->resetCapsule();
			Model::unsetConnectionResolver();
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			$this->resetCapsule();
			Model::unsetConnectionResolver();
			parent::tearDown();
		}

		/**
		 * @return void
		 */
		private function resetCapsule(): void {
			$property = new \ReflectionProperty(ServiceProvider::class, 'capsule');
			$property->setAccessible(true);
			$property->setValue(null, null);
		}

		/**
		 * Boots the Eloquent connection (in-memory SQLite) via the plain
		 * ServiceProvider and builds a ModelBindingServiceProvider wired to a
		 * container that serves an AnnotationReader returning $annotations for
		 * any getMethodAnnotations() call. The fixture's table is created
		 * fresh for each call.
		 * @param array<int, ResolveModel> $annotations
		 */
		private function makeProvider(array $annotations = []): ModelBindingServiceProvider {
			$connectionProvider = new ServiceProvider();
			$connectionProvider->setConfig(['driver' => 'sqlite', 'database' => ':memory:']);

			$annotationReader = $this->createMock(AnnotationReader::class);
			$annotationReader->method('getMethodAnnotations')->willReturn(new AnnotationCollection($annotations));

			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				function (string $className) use ($annotationReader): ?object {
					return match ($className) {
						AnnotationReader::class => $annotationReader,
						default => null,
					};
				}
			);

			Model::getConnectionResolver()->connection()->statement(
				'CREATE TABLE resolve_model_fixture_posts (id INTEGER PRIMARY KEY, title TEXT)'
			);

			return new ModelBindingServiceProvider($container);
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
			$provider = $this->makeProvider();

			$this->assertNull($provider->createInstance(ResolveModelFixturePost::class, [], [], null));
		}

		// =========================================================================
		// Implicit binding by parameter name
		// =========================================================================

		public function testResolvesByParameterNameWhenNoAnnotationPresent(): void {
			$provider = $this->makeProvider();
			ResolveModelFixturePost::create(['id' => 1, 'title' => 'Hello World']);

			$context = $this->makeContext('post', ['post' => 1]);
			$model = $provider->createInstance(ResolveModelFixturePost::class, [], [], $context);

			$this->assertInstanceOf(ResolveModelFixturePost::class, $model);
			$this->assertSame('Hello World', $model->title);
		}

		public function testReturnsNullWhenParameterNameCannotBeDetermined(): void {
			$container = $this->createMock(ContainerInterface::class);
			$container->expects($this->never())->method('get');
			$provider = new ModelBindingServiceProvider($container);
			$context = $this->makeContext(null, ['id' => 1]);

			$this->assertNull($provider->createInstance(ResolveModelFixturePost::class, [], [], $context));
		}

		public function testThrowsBindingExceptionWhenRouteArgumentIsMissing(): void {
			$provider = $this->makeProvider();
			$context = $this->makeContext('post', ['id' => 1]);

			$this->expectException(ModelBindingException::class);
			$this->expectExceptionMessage("route has no value for key 'post'");
			$provider->createInstance(ResolveModelFixturePost::class, [], [], $context);
		}

		public function testThrowsBindingExceptionWhenRouteArgumentIsNotStringOrInt(): void {
			$provider = $this->makeProvider();
			$context = $this->makeContext('post', ['post' => ['not' => 'scalar']]);

			$this->expectException(ModelBindingException::class);
			$this->expectExceptionMessage('composite-key binding is not supported');
			$provider->createInstance(ResolveModelFixturePost::class, [], [], $context);
		}

		// =========================================================================
		// Valid lookup, no row — the only case that throws ResolutionNotFoundException
		// =========================================================================

		public function testThrowsResolutionNotFoundWhenLookupFindsNoRow(): void {
			$provider = $this->makeProvider();
			$context = $this->makeContext('post', ['post' => 999]);

			$this->expectException(ResolutionNotFoundException::class);
			$provider->createInstance(ResolveModelFixturePost::class, [], [], $context);
		}

		// =========================================================================
		// @ResolveModel override
		// =========================================================================

		public function testAnnotationOverridesImplicitParameterNameBinding(): void {
			$annotation = new ResolveModel(parameter: 'post', routeParam: 'postId');
			$provider = $this->makeProvider([$annotation]);
			ResolveModelFixturePost::create(['id' => 1, 'title' => 'Hello World']);

			$context = $this->makeContext('post', ['postId' => 1, 'post' => 999]);
			$model = $provider->createInstance(ResolveModelFixturePost::class, [], [], $context);

			$this->assertSame('Hello World', $model->title);
		}

		public function testAnnotationTargetingADifferentParameterIsIgnored(): void {
			$annotation = new ResolveModel(parameter: 'category', routeParam: 'categoryId');
			$provider = $this->makeProvider([$annotation]);
			ResolveModelFixturePost::create(['id' => 1, 'title' => 'Hello World']);

			$context = $this->makeContext('post', ['post' => 1]);
			$model = $provider->createInstance(ResolveModelFixturePost::class, [], [], $context);

			$this->assertSame('Hello World', $model->title);
		}

		public function testMultipleAnnotationsDisambiguateMultipleParameters(): void {
			$annotations = [
				new ResolveModel(parameter: 'post', routeParam: 'postId'),
				new ResolveModel(parameter: 'category', routeParam: 'categoryId'),
			];

			$provider = $this->makeProvider($annotations);
			ResolveModelFixturePost::create(['id' => 7, 'title' => 'Tech Category']);

			$context = $this->makeContext('category', ['postId' => 1, 'categoryId' => 7]);
			$model = $provider->createInstance(ResolveModelFixturePost::class, [], [], $context);

			$this->assertSame('Tech Category', $model->title);
		}
	}
