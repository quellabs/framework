<?php
	
	// quellabs/payment-contracts
	
	namespace Quellabs\Contracts\Payment;
	
	use Quellabs\Canvas\Validation\Rules\Date;
	use RectorPrefix202602\Nette\Utils\DateTime;
	
	final class RefundRequest {
		
		/**
		 * @param string $transactionId
		 * @param string $paymentModule
		 * @param float $amount The amount to charge
		 * @param string $currency ISO 4217 currency code (EUR, USD, ...)
		 * @param string $description Human-readable description shown to the customer
		 * @param DateTime $issueDate
		 */
		public function __construct(
			public string   $transactionId,
			public string   $paymentModule,
			public float    $amount,
			public string   $currency,
			public string   $description,
			public DateTime $issueDate = new DateTime(),
		) {
		}
	}