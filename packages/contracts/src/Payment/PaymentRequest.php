<?php
	
	// quellabs/payment-contracts
	
	namespace Quellabs\Contracts\Payment;
	
	final class PaymentRequest {
		
		/**
		 * @param float $amount The amount to charge
		 * @param string $currency ISO 4217 currency code (EUR, USD, ...)
		 * @param string $description Human-readable description shown to the customer
		 * @param string $reference Your internal reference (order ID, invoice number, ...)
		 * @param array $metadata Arbitrary key/value pairs passed through to the provider
		 */
		public function __construct(
			public string  $paymentModule,
			public float   $amount,
			public string  $currency,
			public string  $description,
			public string  $reference,
			public ?string $issuerId = null,
			public ?string $webhookUrl = null,
			public ?string $redirectUrl = null,
			public ?string $cancelUrl = null,
			public array   $metadata = [],
			public ?PaymentAddress $billingAddress  = null,
			public ?PaymentAddress $shippingAddress = null,
		) {
		}
	}