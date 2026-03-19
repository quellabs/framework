<?php
	
	namespace Quellabs\Payments\Contracts;
	
	final class RefundRequest {
		
		/**
		 * @param string $transactionId
		 * @param string $paymentModule
		 * @param string $currency ISO 4217 currency code (EUR, USD, ...)
		 * @param string $description Human-readable description shown to the customer
		 * @param int|null $amount The amount to refund in minor units, or null to refund the whole amount
		 * @param \DateTimeImmutable $issueDate
		 */
		public function __construct(
			public string             $transactionId,
			public string             $paymentModule,
			public string             $currency,
			public string             $description,
			public ?int               $amount = null,
			public \DateTimeImmutable $issueDate = new \DateTimeImmutable(),
		) {
		}
	}