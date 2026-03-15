<?php
	
	namespace Quellabs\Contracts\Payment;
	
	final readonly class PaymentResponse {
		
		private function __construct(
			public bool   $success,
			public mixed  $data,
			public int    $errorId = 0,
			public string $errorMessage = '',
		) {
		}
		
		public static function ok(mixed $data = null): self {
			return new self(success: true, data: $data);
		}
		
		public static function fail(string $errorId, string $errorMessage, mixed $data = null): self {
			return new self(success: false, data: $data, errorId: $errorId, errorMessage: $errorMessage);
		}
	}
