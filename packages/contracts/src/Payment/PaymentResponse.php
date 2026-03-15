<?php
	
	namespace Quellabs\Contracts\Payment;
	
	final readonly class PaymentResponse {
		
		private function __construct(
			public bool   $success,
			public mixed  $response,
			public int    $errorId = 0,
			public string $errorMessage = '',
		) {
		}
		
		public static function ok(mixed $response = null): self {
			return new self(success: true, response: $response);
		}
		
		public static function fail(string $errorId, string $errorMessage, mixed $response = null): self {
			return new self(success: false, response: $response, errorId: $errorId, errorMessage: $errorMessage);
		}
	}
