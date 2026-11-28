<?php

	namespace Quellabs\Canvas\Tests\Fixtures\ErrorHandlers;

	use Quellabs\Canvas\Error\ErrorHandlerInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * A custom error handler that rethrows the exception it was given.
	 */
	class RethrowingErrorHandler implements ErrorHandlerInterface {

		/**
		 * Claims every exception.
		 * @param \Throwable $e
		 * @return bool
		 */
		public static function supports(\Throwable $e): bool {
			return true;
		}

		/**
		 * Rethrows $e unchanged.
		 * @param \Throwable $e
		 * @param Request $request
		 * @return Response
		 */
		public function handle(\Throwable $e, Request $request): Response {
			throw $e;
		}
	}
