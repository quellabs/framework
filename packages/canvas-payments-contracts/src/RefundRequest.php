<?php
	
	namespace Quellabs\Payments\Contracts;
	
	final class RefundRequest {

		/** The provider-specific identifier of the payment to refund */
		public string $paymentReference;

		public string $paymentModule;

		/** ISO 4217 currency code (EUR, USD, ...) */
		public string $currency;

		/** Human-readable description shown to the customer */
		public string $description;

		/** The amount to refund in minor units, or null to refund the whole amount */
		public ?int $amount;

		public \DateTimeImmutable $issueDate;

		/**
		 * @param string $paymentReference The provider-specific identifier of the payment to refund
		 * @param string $paymentModule
		 * @param string $currency ISO 4217 currency code (EUR, USD, ...)
		 * @param string $description Human-readable description shown to the customer
		 * @param int|null $amount The amount to refund in minor units, or null to refund the whole amount
		 * @param \DateTimeImmutable $issueDate
		 */
		public function __construct(
			string             $paymentReference,
			string             $paymentModule,
			string             $currency,
			string             $description,
			?int               $amount = null,
			\DateTimeImmutable $issueDate = new \DateTimeImmutable(),
		) {
			$this->paymentReference = $paymentReference;
			$this->paymentModule = $paymentModule;
			$this->currency = $currency;
			$this->description = $description;
			$this->amount = $amount;
			$this->issueDate = $issueDate;
		}
	}