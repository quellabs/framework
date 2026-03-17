<?php
	
	namespace Quellabs\Payments\Contracts;
	
	use RuntimeException;
	
	class PaymentException extends RuntimeException {
		
		public function __construct(
			private readonly string $provider,
			private readonly int $errorId,
			string $message,
			?\Throwable $previous = null
		) {
			parent::__construct($message, 0, $previous);
		}
		
		public function getProvider(): string {
			return $this->provider;
		}
		
		public function getErrorId(): int {
			return $this->errorId;
		}
	}