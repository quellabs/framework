<?php
	
	namespace Quellabs\Payments\Contracts;
	
	use RuntimeException;
	
	/**
	 * Base exception for all payment provider errors.
	 * Carries the provider identifier and the provider's own error code alongside the message.
	 */
	class PaymentException extends RuntimeException {

		/** Identifier of the provider that raised the error (e.g. 'paypal', 'mollie') */
		private readonly string $provider;

		/** Provider-specific error code. Numeric for legacy NVP-style APIs, string for REST APIs. */
		private readonly int|string $errorId;

		/**
		 * PaymentException constructor
		 * @param string $provider
		 * @param int|string $errorId
		 * @param string $message
		 * @param \Throwable|null $previous
		 */
		public function __construct(
			string      $provider,
			int|string  $errorId,
			string      $message,
			?\Throwable $previous = null
		) {
			$this->provider = $provider;
			$this->errorId = $errorId;
			parent::__construct($message, 0, $previous);
		}
		
		/**
		 * Returns the identifier of the provider that raised this exception.
		 * @return string
		 */
		public function getProvider(): string {
			return $this->provider;
		}
		
		/**
		 * Returns the provider-specific error code.
		 * @return int|string
		 */
		public function getErrorId(): int|string {
			return $this->errorId;
		}
	}