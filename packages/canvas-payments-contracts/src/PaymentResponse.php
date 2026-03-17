<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Immutable value object representing the outcome of a payment operation.
	 * Use the static factory methods ok() and fail() to construct instances;
	 * the constructor is intentionally private to enforce this pattern.
	 */
	final readonly class PaymentResponse {
		
		/**
		 * PaymentResponse constructor
		 * @param bool   $success      Whether the payment operation succeeded.
		 * @param mixed  $response     The raw response payload from the payment provider.
		 * @param int    $errorId      Provider-specific numeric error code; 0 when successful.
		 * @param string $errorMessage Human-readable error description; empty when successful.
		 */
		private function __construct(
			public bool   $success,
			public mixed  $response,
			public int    $errorId = 0,
			public string $errorMessage = '',
		) {
		}
		
		/**
		 * Creates a successful payment response.
		 * @param  mixed $response Optional raw payload returned by the payment provider.
		 * @return self
		 */
		public static function ok(mixed $response = null): self {
			return new self(success: true, response: $response);
		}
		
		/**
		 * Creates a failed payment response.
		 * @param  int $errorId         Provider-specific error code.
		 * @param  string $errorMessage Human-readable description of the failure.
		 * @param  mixed  $response     Optional raw payload returned by the payment provider.
		 * @return self
		 */
		public static function fail(int $errorId, string $errorMessage, mixed $response = null): self {
			return new self(success: false, response: $response, errorId: $errorId, errorMessage: $errorMessage);
		}
	}