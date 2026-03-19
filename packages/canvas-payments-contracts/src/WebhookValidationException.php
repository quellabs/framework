<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Thrown by webhook parsing when the incoming request is invalid or cannot be verified.
	 * Carries an HTTP status code so the controller can return the appropriate response
	 * without encoding HTTP semantics inside the parser itself.
	 */
	class WebhookValidationException extends \RuntimeException {
		
		/**
		 * @param string $message    Human-readable reason for rejection
		 * @param int    $statusCode HTTP status code to return to the caller (typically 400)
		 */
		public function __construct(string $message, private readonly int $statusCode = 400) {
			parent::__construct($message);
		}
		
		/**
		 * @return int
		 */
		public function getStatusCode(): int {
			return $this->statusCode;
		}
	}