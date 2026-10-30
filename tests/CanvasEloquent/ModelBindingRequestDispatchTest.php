<?php

	namespace Quellabs\CanvasEloquent\Tests;

	use Illuminate\Database\Eloquent\Model;
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\RequestHandler;
	use Quellabs\CanvasEloquent\ServiceProvider;
	use Quellabs\CanvasEloquent\Tests\Fixtures\Controllers\ModelBindingPostController;
	use Quellabs\CanvasEloquent\Tests\Fixtures\Models\ModelBindingPost;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Integration coverage for the model-binding contract across the real
	 * Container/Autowirer/CanvasAutowirer resolution chain and canvas-
	 * eloquent's real ServiceProvider: a required parameter whose lookup
	 * finds no row must come back as a genuine HTTP 404 Response produced by
	 * Kernel's actual error-handling path (not a hand-built one); the
	 * nullable counterpart must come back as a normal 200 with null injected.
	 *
	 * This is NOT a route-discovery or Kernel::handle() test. An already-
	 * matched route is supplied directly to
	 * RequestHandler::executeCanvasRoute() (the same private method Kernel's
	 * real routing calls once a route has matched, invoked here via
	 * reflection), and Kernel::handle()'s own try/catch-to-Response conversion
	 * is reproduced by hand around it. Also covers the @ResolveModel
	 * annotation path end to end, through the real AnnotationReader rather
	 * than a mock.
	 *
	 * Unlike canvas-objectquel's equivalent test, Eloquent's Model::find() is
	 * a static call on the model class itself — there is no injectable
	 * manager to mock. A real Kernel's own construction auto-discovers
	 * canvas-eloquent's ServiceProvider against the project's configured
	 * connection (config/eloquent.php, the same MySQL database
	 * tests/bootstrap.php uses), so this test seeds a dedicated table on
	 * that real connection instead, truncated and reseeded before each case.
	 */
	class ModelBindingRequestDispatchTest extends TestCase {

		/**
		 * Resets the package's cached Capsule and Eloquent's static connection
		 * resolver before/after each test. Both are process-wide static state
		 * that would otherwise leak between tests in this suite.
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
		 * Builds a real Kernel, which auto-discovers canvas-eloquent's real
		 * ServiceProvider against the project's configured connection, and
		 * seeds a dedicated test table — truncated first for isolation —
		 * with the given rows.
		 * @param array<int, array{id: int, title: string}> $rows
		 * @return Kernel
		 */
		private function makeKernel(array $rows): Kernel {
			$kernel = new Kernel();

			$connection = Model::getConnectionResolver()->connection();
			$connection->statement(
				'CREATE TABLE IF NOT EXISTS model_binding_test_posts (' .
				'id INT UNSIGNED NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL)'
			);
			$connection->statement('TRUNCATE TABLE model_binding_test_posts');

			foreach ($rows as $row) {
				ModelBindingPost::create($row);
			}

			return $kernel;
		}

		/**
		 * Dispatches an already-matched route through the real
		 * RequestHandler::executeCanvasRoute(), with Kernel::handle()'s own
		 * exception-to-Response conversion reproduced around it. Route
		 * matching itself is not exercised — $urlData is supplied as if a
		 * route had already matched.
		 * @param Kernel $kernel
		 * @param string $method Controller method to dispatch to
		 * @param array<string, mixed> $variables Matched route variables
		 * @return Response
		 */
		private function dispatch(Kernel $kernel, string $method, array $variables): Response {
			$urlData = [
				'controller' => ModelBindingPostController::class,
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

		public function testRequiredParameterResolvesTheModelWhenARowExists(): void {
			$kernel = $this->makeKernel([['id' => 1, 'title' => 'Hello World']]);

			$response = $this->dispatch($kernel, 'show', ['post' => 1]);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Hello World', $response->getContent());
		}

		public function testRequiredParameterProducesA404WhenNoRowExists(): void {
			$kernel = $this->makeKernel([]);

			$response = $this->dispatch($kernel, 'show', ['post' => 999]);

			$this->assertSame(404, $response->getStatusCode());
		}

		public function testMissingRouteKeyProducesA500NotA404(): void {
			$kernel = $this->makeKernel([['id' => 1, 'title' => 'Hello World']]);

			// The route simply has no 'post' value at all — a binding/
			// configuration mistake (ModelBindingException), not "no row
			// found", so this must come back as a server error, never a 404.
			$response = $this->dispatch($kernel, 'show', []);

			$this->assertSame(500, $response->getStatusCode());
		}

		public function testNullableParameterResolvesTheModelWhenARowExists(): void {
			$kernel = $this->makeKernel([['id' => 1, 'title' => 'Hello World']]);

			$response = $this->dispatch($kernel, 'showOptional', ['post' => 1]);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Hello World', $response->getContent());
		}

		public function testNullableParameterReceivesNullWhenNoRowExists(): void {
			$kernel = $this->makeKernel([]);

			$response = $this->dispatch($kernel, 'showOptional', ['post' => 999]);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('not-found', $response->getContent());
		}

		public function testResolveModelAnnotationBindsARouteParameterWithADifferentName(): void {
			$kernel = $this->makeKernel([['id' => 1, 'title' => 'Hello World']]);

			// {postId} binds to $post only via the real @ResolveModel
			// annotation, parsed by the real AnnotationReader.
			$response = $this->dispatch($kernel, 'showByAnnotation', ['postId' => 1]);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('Hello World', $response->getContent());
		}
	}
