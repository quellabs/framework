<?php

	namespace Quellabs\Payments\Contracts;

	final class RecurringChargeRequest {

		/** Identifier of the payment module/provider to use (e.g. 'mollie_ideal') */
		public string $paymentModule;

		/** Provider-assigned customer identifier the mandate belongs to */
		public string $customerReference;

		/** The amount to charge in minor units (e.g. 999 for €9.99) */
		public int $amount;

		/** ISO 4217 currency code (EUR, USD, ...) */
		public string $currency;

		/** Human-readable description shown on the customer's statement */
		public string $description;

		/** Specific mandate to charge. Null lets the provider pick the customer's active mandate */
		public ?string $mandateId;

		/** Merchant-assigned order reference passed to the provider (e.g. as invoice number or order ID) */
		public ?string $reference;

		/** URL the provider calls with asynchronous payment status updates */
		public ?string $webhookUrl;

		/** @var array<string, mixed> Arbitrary key/value pairs passed through to the provider unchanged */
		public array $metadata;

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
			string $paymentModule,
			string $customerReference,
			int    $amount,
			string $currency,
			string $description,
			?string $mandateId = null,
			?string $reference = null,
			?string $webhookUrl = null,
			array  $metadata = [],
		) {
			$this->paymentModule = $paymentModule;
			$this->customerReference = $customerReference;
			$this->amount = $amount;
			$this->currency = $currency;
			$this->description = $description;
			$this->mandateId = $mandateId;
			$this->reference = $reference;
			$this->webhookUrl = $webhookUrl;
			$this->metadata = $metadata;
		}
	}
