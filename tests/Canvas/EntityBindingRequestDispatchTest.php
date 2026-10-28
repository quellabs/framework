<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\RequestHandler;
	use Quellabs\Canvas\Tests\Fixtures\Entities\EntityBindingPostEntity;
	use Quellabs\Canvas\Tests\Fixtures\EntityBindingControllers\EntityBindingPostController;
	use Quellabs\CanvasObjectQuel\ServiceProvider;
	use Quellabs\DependencyInjection\Provider\SimpleBinding;
	use Quellabs\ObjectQuel\EntityManager;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Integration coverage for the entity-binding contract across the real
	 * Container/Autowirer/CanvasAutowirer resolution chain and
	 * canvas-objectquel's real ServiceProvider: a required parameter whose
	 * lookup finds no row must come back as a genuine HTTP 404 Response
	 * produced by Kernel's actual error-handling path (not a hand-built one);
	 * the nullable counterpart must come back as a normal 200 with null
	 * injected.
	 *
	 * This is NOT a route-discovery or Kernel::handle() test. An already-
	 * matched route is supplied directly to
	 * RequestHandler::executeCanvasRoute() (the same private method Kernel's
	 * real routing calls once a route has matched, invoked here via
	 * reflection), and Kernel::handle()'s own try/catch-to-Response conversion
	 * is reproduced by hand around it — route matching and Kernel::handle()
	 * itself are not exercised. That's deliberate: Kernel's
	 * controller_directory scan goes through
	 * ComposerUtils::findClassesInDirectory(), which — independently of this
	 * change — cannot resolve a PSR-4 namespace for any path under tests/
	 * (confirmed against the pre-existing tests/Canvas/Fixtures/Controllers
	 * too), because it string-matches against the autoloader's raw,
	 * un-normalized "vendor/composer/../../tests/Canvas" path instead of a
	 * resolved one; fixing that is a separate, unrelated bug in
	 * quellabs/support. Route matching has its own dedicated coverage
	 * elsewhere (RouteMatcherTest, AnnotationResolverMethodHandlingTest,
	 * etc.), so this test's job is narrower: everything from
	 * executeCanvasRoute() down — DI, autowiring, entity binding, and error
	 * mapping — is real.
	 *
	 * EntityManager::find() itself is mocked rather than backed by a second
	 * real EntityManager: ObjectQuel's UnitOfWork registers process-wide
	 * SignalHub signals with no duplicate-registration guard, and
	 * tests/bootstrap.php already creates the one EntityManager this PHPUnit
	 * process is allowed to have (see its comments).
	 */
	class EntityBindingRequestDispatchTest extends TestCase {

		/**
		 * Builds a Kernel with a mocked EntityManager and a canvas-objectquel
		 * ServiceProvider configured to recognize the fixture entity's namespace.
		 * @param EntityBindingPostEntity|null $findResult What EntityManager::find() returns
		 */
		private function makeKernel(?EntityBindingPostEntity $findResult): Kernel {
			$kernel = new Kernel();
			$container = $kernel->getDependencyInjector();

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('find')->willReturn($findResult);
			$container->register(new SimpleBinding(EntityManager::class, $entityManager));

			$entityProvider = new ServiceProvider($container);
			$entityProvider->setConfig(['entity_namespace' => 'Quellabs\\Canvas\\Tests\\Fixtures\\Entities']);
			$container->register($entityProvider);

			return $kernel;
		}

		/**
		 * Dispatches an already-matched route through the real
		 * RequestHandler::executeCanvasRoute(), with Kernel::handle()'s own
		 * exception-to-Response conversion reproduced around it. Route matching
		 * itself is not exercised — $urlData is supplied as if a route had
		 * already matched.
		 * @param array<string, mixed> $variables Matched route variables
		 */
		private function dispatch(Kernel $kernel, string $method, array $variables): Response {
			$urlData = [
				'controller' => EntityBindingPostController::class,
				'method'     => $method,
				'variables'  => $variables,
			];

			$handler = new RequestHandler($kernel);
			$reflection = new \ReflectionMethod($handler, 'executeCanvasRoute');
			$reflection->setAccessible(true);

			try {
				return $reflection->invoke($handler, Request::create('/'), $urlData);
			} catch (\Throwable $e) {
				return $kernel->createErrorResponse($e, Request::create('/'));
			}
		}

		private function makePost(string $slug, string $title): EntityBindingPostEntity {
			$post = new EntityBindingPostEntity();
			$post->setSlug($slug);
			$post->setTitle($title);
			return $post;
		}

		public function testRequiredParameterResolvesTheEntityWhenARowExists(): void {
			$kernel = $this->makeKernel($this->makePost('hello-world', 'Hello World'));

			$response = $this->dispatch($kernel, 'show', ['slug' => 'hello-world']);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Hello World', $response->getContent());
		}

		public function testRequiredParameterProducesA404WhenNoRowExists(): void {
			$kernel = $this->makeKernel(null);

			$response = $this->dispatch($kernel, 'show', ['slug' => 'does-not-exist']);

			$this->assertSame(404, $response->getStatusCode());
		}

		public function testNullableParameterResolvesTheEntityWhenARowExists(): void {
			$kernel = $this->makeKernel($this->makePost('hello-world', 'Hello World'));

			$response = $this->dispatch($kernel, 'showOptional', ['slug' => 'hello-world']);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Hello World', $response->getContent());
		}

		public function testNullableParameterReceivesNullWhenNoRowExists(): void {
			$kernel = $this->makeKernel(null);

			$response = $this->dispatch($kernel, 'showOptional', ['slug' => 'does-not-exist']);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('not-found', $response->getContent());
		}
	}
