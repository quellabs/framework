<?php

	namespace Quellabs\Canvas\Tests\Error;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Error\DefaultErrorHandler;
	use Quellabs\Canvas\Exceptions\MethodNotAllowedException;
	use Quellabs\Canvas\Exceptions\RouteNotFoundException;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Unit tests for DefaultErrorHandler.
	 *
	 * Covers the status/Allow-header handling specific to method-aware routing;
	 * does not re-test unrelated error rendering already covered elsewhere.
	 */
	class DefaultErrorHandlerTest extends TestCase {

		private DefaultErrorHandler $handler;

		protected function setUp(): void {
			$this->handler = new DefaultErrorHandler();
		}

		public function testMethodNotAllowedProducesA405WithAllowHeader(): void {
			$exception = new MethodNotAllowedException(['GET', 'HEAD', 'OPTIONS', 'POST']);

			$response = $this->handler->handle($exception, Request::create('/ping', 'DELETE'), false);

			$this->assertSame(405, $response->getStatusCode());
			$this->assertSame('GET, HEAD, OPTIONS, POST', $response->headers->get('Allow'));
		}

		public function testRouteNotFoundHasNoAllowHeader(): void {
			$exception = new RouteNotFoundException('Route not found');

			$response = $this->handler->handle($exception, Request::create('/nonexistent', 'GET'), false);

			$this->assertSame(404, $response->getStatusCode());
			$this->assertFalse($response->headers->has('Allow'));
		}
	}
