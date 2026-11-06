<?php

	namespace Quellabs\CanvasAuthorization\Exceptions;

	use Quellabs\Contracts\Http\HttpException;
	use Throwable;

	/**
	 * Thrown when AccountEligibilityAspect's check fails and throwOnFailure is enabled.
	 *
	 * The HTTP status code is always 403 — the request may well be authenticated
	 * (or have no session at all; this aspect doesn't distinguish in its status
	 * code, only in its message), it's just not allowed to proceed. Register an
	 * ErrorHandlerInterface that handles this exception type to produce a
	 * redirect or JSON response; the default Canvas error handler returns HTML.
	 */
	class AccountEligibilityException extends HttpException {

		/**
		 * AccountEligibilityException constructor
		 * @param string $message Reason the account is not eligible
		 * @param Throwable|null $previous Previous exception for chaining
		 */
		public function __construct(string $message = 'Account is not eligible', ?Throwable $previous = null) {
			parent::__construct($message, 403, $previous);
		}
	}
