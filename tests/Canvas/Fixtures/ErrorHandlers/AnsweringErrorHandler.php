<?php

	namespace Quellabs\Canvas\Tests\Fixtures\ErrorHandlers;

	use Quellabs\Canvas\Error\ErrorHandlerInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * A custom error handler that answers every exception itself, used to
	 * prove that an exception a project's own handler deals with is left to
	 * that handler and not logged by the Kernel.
	 */
	class AnsweringErrorHandler implements ErrorHandlerInterface {

		/**
		 * Handles every exception.
		 * @param \Throwable $e
		 * @return bool
		 */
		public static function supports(\Throwable $e): bool {
			return true;
		}

		/**
		 * Answers with a fixed 503.
		 * @param \Throwable $e
		 * @param Request $request
		 * @return Response
		 */
		public function handle(\Throwable $e, Request $request): Response {
			return new Response('handled', Response::HTTP_SERVICE_UNAVAILABLE);
		}
	}
