<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\RequestHandler;
	use Quellabs\Canvas\Tests\Fixtures\RouteParameterCoercionControllers\RouteParameterCoercionController;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Regression coverage: VariableSegmentStrategy stores every route segment
	 * as a plain string, and Autowirer's direct-value check must still accept
	 * a numeric one for an `int $id` parameter so PHP's own weak-typing
	 * coercion (no strict_types is declared anywhere in Canvas's own call
	 * chain) converts it at the actual method call, exactly as it did before
	 * Autowirer started validating declared types against the supplied value.
	 * A non-numeric segment, which was never a valid int in the first place,
	 * must still fail clearly rather than reach the method call at all.
	 */
	class RouteParameterCoercionDispatchTest extends TestCase {

		/**
		 * Dispatches an already-matched route through the real
		 * RequestHandler::executeCanvasRoute(), reproducing Kernel::handle()'s
		 * own exception-to-Response conversion by hand around it - the same
		 * pattern EntityBindingRequestDispatchTest uses, for the same reason
		 * route discovery itself cannot run against a path under tests/.
		 * @param string $method Controller method to dispatch to
		 * @param array<string, mixed> $variables Matched route variables
		 * @return Response
		 */
		private function dispatch(string $method, array $variables): Response {
			$kernel = new Kernel();

			$urlData = [
				'controller' => RouteParameterCoercionController::class,
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

		public function testNumericRouteSegmentCoercesToAnIntParameter(): void {
			// '42' is a string, exactly as VariableSegmentStrategy would store it -
			// not the native int 42.
			$response = $this->dispatch('showById', ['id' => '42']);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('43', $response->getContent());
		}

		public function testNonNumericRouteSegmentForAnIntParameterFailsClearlyInsteadOfReachingTheMethod(): void {
			$response = $this->dispatch('showByInvalidId', ['id' => 'not-a-number']);

			$this->assertSame(500, $response->getStatusCode());
		}
	}
