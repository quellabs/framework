<?php

	namespace Quellabs\Canvas\Tests\Error;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Error\DefaultErrorHandler;
	use Quellabs\Canvas\Error\ProvidesResponseHeaders;
	use Quellabs\Canvas\Exceptions\MethodNotAllowedException;
	use Quellabs\Canvas\Exceptions\RateLimitException;
	use Quellabs\Canvas\Exceptions\RouteNotFoundException;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Unit tests for DefaultErrorHandler.
	 *
	 * Covers the status/Allow-header handling specific to method-aware routing,
	 * RateLimitException's headers, ProvidesResponseHeaders generically, and the
	 * optional global SecurityHeaderPolicy; does not re-test unrelated error
	 * rendering already covered elsewhere.
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

		public function testRateLimitExceptionAppliesItsOwnHeadersIncludingRetryAfter(): void {
			$exception = new RateLimitException(
				limit: 10,
				remaining: 0,
				retryAfter: 42,
				resetTime: 1700000000,
				strategy: 'fixed_window',
				scope: 'ip',
				headers: [
					'X-RateLimit-Limit'     => 10,
					'X-RateLimit-Remaining' => 0,
					'X-RateLimit-Reset'     => 1700000000,
					'Retry-After'           => 42,
				]
			);

			$response = $this->handler->handle($exception, Request::create('/api/data'), false);

			$this->assertSame(429, $response->getStatusCode());
			$this->assertSame('42', $response->headers->get('Retry-After'));
			$this->assertSame('10', $response->headers->get('X-RateLimit-Limit'));
		}

		public function testAnyExceptionImplementingProvidesResponseHeadersGetsThemApplied(): void {
			$exception = new class ('Custom limiter tripped', 429) extends \Exception implements ProvidesResponseHeaders {
				public function __construct(string $message, private readonly int $status) {
					parent::__construct($message, $status);
				}

				public function getHeaders(): array {
					return ['Retry-After' => 17, 'X-Custom-Limiter' => 'tripped'];
				}
			};

			$response = $this->handler->handle($exception, Request::create('/third-party'), false);

			$this->assertSame(429, $response->getStatusCode());
			$this->assertSame('17', $response->headers->get('Retry-After'));
			$this->assertSame('tripped', $response->headers->get('X-Custom-Limiter'));
		}

		public function testSecurityHeaderPolicyIsAppliedWhenConfigured(): void {
			$handler = new DefaultErrorHandler(new BasicSecurityHeaderPolicy(frameOptions: 'DENY'));
			$exception = new RouteNotFoundException('Route not found');

			$response = $handler->handle($exception, Request::create('/missing'), false);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		}

		public function testNoSecurityHeaderPolicyLeavesHeadersUnset(): void {
			$exception = new RouteNotFoundException('Route not found');

			$response = $this->handler->handle($exception, Request::create('/missing'), false);

			$this->assertFalse($response->headers->has('X-Frame-Options'));
		}
	}
