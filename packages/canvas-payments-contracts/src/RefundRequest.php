<?php
	
	namespace Quellabs\Payments\Contracts;
	
	final class RefundRequest {
		
		/**
		 * @param string $transactionId
		 * @param string $paymentModule
		 * @param int $amount The amount to refund in minor units
		 * @param string $currency ISO 4217 currency code (EUR, USD, ...)
		 * @param string $description Human-readable description shown to the customer
		 * @param \DateTimeImmutable $issueDate
		 */
		public function __construct(
			public string             $transactionId,
			public string             $paymentModule,
			public int                $amount,
			public string             $currency,
			public string             $description,
			public \DateTimeImmutable $issueDate = new \DateTimeImmutable(),
		) {
		}
	}