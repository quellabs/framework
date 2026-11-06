<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Tests\Fixtures\ErrorHandlers\ThrowingErrorHandler;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Kernel::createErrorResponse() must never let a broken project-supplied
	 * ErrorHandlerInterface implementation propagate out uncaught - handle()'s
	 * post-processing (security headers, output filter, HEAD handling) only
	 * runs on whatever Response this method returns, so a handler bug must
	 * fall back to DefaultErrorHandler instead of skipping that entirely.
	 */
	class KernelErrorHandlerFallbackTest extends TestCase {

		public function testAThrowingCustomErrorHandlerFallsBackToTheDefaultHandler(): void {
			$kernel = new Kernel();

			$property = new \ReflectionProperty($kernel, 'errorHandlers');
			$property->setAccessible(true);
			$property->setValue($kernel, [ThrowingErrorHandler::class]);

			$response = $kernel->createErrorResponse(new \RuntimeException('original error'), Request::create('/'));

			$this->assertInstanceOf(Response::class, $response);
			$this->assertGreaterThanOrEqual(500, $response->getStatusCode());
		}
	}
