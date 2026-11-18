<?php

	namespace App\Errors;

	use Quellabs\Canvas\Error\ErrorHandlerInterface;
	use Quellabs\CanvasAuthorization\Exceptions\AccountEligibilityException;
	use Quellabs\CanvasAuthorization\Exceptions\SessionAuthenticationException;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Converts authentication failures from SessionAuthenticationAspect and
	 * AccountEligibilityAspect into a redirect to the login page.
	 *
	 * Neither aspect builds a Response itself — with throwOnFailure=true (as set
	 * on AuthenticatedController), they throw instead, and this handler is what
	 * turns that into an actual page protection. Edit the redirect target below
	 * if your login route isn't /login.
	 */
	class AuthErrorHandler implements ErrorHandlerInterface {

		/**
		 * Determine whether this handler can process the given exception.
		 * @param \Throwable $e The thrown exception.
		 * @return bool True if this handler supports the exception.
		 */
		public static function supports(\Throwable $e): bool {
			return $e instanceof SessionAuthenticationException || $e instanceof AccountEligibilityException;
		}

		/**
		 * Convert the supported exception into a redirect to the login page.
		 * @param \Throwable $e The exception being handled.
		 * @param Request $request The current HTTP request.
		 * @return Response The generated HTTP response.
		 */
		public function handle(\Throwable $e, Request $request): Response {
			return new RedirectResponse('/login');
		}
	}
