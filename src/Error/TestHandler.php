<?php
	
	namespace App\Error;
	
	use Quellabs\Canvas\Error\ErrorHandlerInterface;
	use Quellabs\Canvas\Exceptions\RouteNotFoundException;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	/**
	 * Example error handler implementation.
	 *
	 * This handler is responsible for converting RouteNotFoundException
	 * instances into an HTTP response.
	 *
	 * It will be discovered during application boot and evaluated during
	 * exception resolution. If supports() returns true, the framework will
	 * instantiate this handler and call handle().
	 */
	class TestHandler implements ErrorHandlerInterface {

		/**
		 * Determine whether this handler can process the given exception.
		 * @param \Throwable $e The thrown exception.
		 * @return bool True if this handler supports the exception.
		 */
		public static function supports(\Throwable $e): bool {
			return false;
			// return $e instanceof RouteNotFoundException;
		}
		
		/**
		 * Convert the supported exception into an HTTP response.
		 * @param \Throwable $e The exception being handled.
		 * @param Request $request The current HTTP request.
		 * @return Response The generated HTTP response.
		 */
		public function handle(\Throwable $e, Request $request): Response {
			return new Response("error");
		}
	}
