<?php

	namespace Quellabs\Payments\Contracts;

	final class RecurringChargeRequest {

		/**
		 * @param string $paymentModule Identifier of the payment module/provider to use (e.g. 'mollie_ideal').
		 * @param string $customerReference Provider-assigned customer identifier the mandate belongs to.
		 * @param int $amount The amount to charge in minor units (e.g. 999 for €9.99).
		 * @param string $currency ISO 4217 currency code (EUR, USD, ...).
		 * @param string $description Human-readable description shown on the customer's statement.
		 * @param string|null $mandateId Specific mandate to charge. Null lets the provider pick the customer's active mandate.
		 * @param string|null $reference Merchant-assigned order reference passed to the provider (e.g. as invoice number or order ID).
		 * @param string|null $webhookUrl URL the provider calls with asynchronous payment status updates.
		 * @param array<string, mixed> $metadata Arbitrary key/value pairs passed through to the provider unchanged.
		 */
		public function __construct(
			public string $paymentModule,
			public string $customerReference,
			public int    $amount,
			public string $currency,
			public string $description,
			public ?string $mandateId = null,
			public ?string $reference = null,
			public ?string $webhookUrl = null,
			public array  $metadata = [],
		) {
		}
	}
