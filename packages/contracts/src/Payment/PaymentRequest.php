<?php

// quellabs/payment-contracts
	
	namespace Quellabs\Contracts\Payment;
	
	final class PaymentRequest {
		
		/**
		 * @param string         $paymentModule   Identifier of the payment module/provider to use (e.g. 'mollie', 'stripe').
		 * @param int            $amount          The amount to charge in minor units (e.g. 999 for €9.99).
		 * @param string         $currency        ISO 4217 currency code (EUR, USD, ...).
		 * @param string         $description     Human-readable description shown to the customer on the payment page.
		 * @param string|null    $issuerId        Provider-specific issuer identifier (e.g. a specific bank for iDEAL).
		 * @param string|null    $webhookUrl      URL the provider calls with asynchronous payment status updates.
		 * @param string|null    $redirectUrl     URL the customer is sent to after a successful payment.
		 * @param string|null    $cancelUrl       URL the customer is sent to if they cancel or abandon the payment.
		 * @param array          $metadata        Arbitrary key/value pairs passed through to the provider unchanged.
		 * @param PaymentAddress|null $billingAddress  Billing address associated with this payment.
		 * @param PaymentAddress|null $shippingAddress Physical delivery address for this order, if applicable.
		 */
		public function __construct(
			public string  $paymentModule,
			public int     $amount,
			public string  $currency,
			public string  $description,
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