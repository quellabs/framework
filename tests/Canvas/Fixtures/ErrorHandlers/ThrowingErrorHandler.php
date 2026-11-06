<?php

	namespace Quellabs\Canvas\Tests\Fixtures\ErrorHandlers;

	use Quellabs\Canvas\Error\ErrorHandlerInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * A deliberately broken custom error handler, used to prove that
	 * Kernel::createErrorResponse() falls back to DefaultErrorHandler rather
	 * than letting a project-supplied handler's own bug crash the pipeline.
	 */
	class ThrowingErrorHandler implements ErrorHandlerInterface {

		public static function supports(\Throwable $e): bool {
			return true;
		}

		public function handle(\Throwable $e, Request $request): Response {
			throw new \RuntimeException('Broken custom error handler');
		}
	}
