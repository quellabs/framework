<?php

// quellabs/payment-contracts
	
	namespace Quellabs\Payments\Contracts;
	
	final class PaymentRequest {

		/** Identifier of the payment module/provider to use (e.g. 'mollie', 'stripe') */
		public string $paymentModule;

		/** The amount to charge in minor units (e.g. 999 for €9.99) */
		public int $amount;

		/** ISO 4217 currency code (EUR, USD, ...) */
		public string $currency;

		/** Human-readable description shown to the customer on the payment page */
		public string $description;

		/** Merchant-assigned order reference passed to the provider (e.g. as invoice number or order ID) */
		public ?string $reference;

		/** Provider-specific issuer identifier (e.g. a specific bank for iDEAL) */
		public ?string $issuerId;

		/** URL the provider calls with asynchronous payment status updates */
		public ?string $webhookUrl;

		/** URL the customer is sent to after a successful payment */
		public ?string $redirectUrl;

		/** URL the customer is sent to if they cancel or abandon the payment */
		public ?string $cancelUrl;

		/** @var array<string, mixed> Arbitrary key/value pairs passed through to the provider unchanged */
		public array $metadata;

		/** Billing address associated with this payment */
		public ?PaymentAddress $billingAddress;

		/** Physical delivery address for this order, if applicable */
		public ?PaymentAddress $shippingAddress;

		/** Marks this as a one-off, first (mandate-creating), or recurring payment. Null behaves as OneOff */
		public ?SequenceType $sequenceType;

		/**
		 * Existing provider customer id to attach this payment to. When $sequenceType
		 * is First and this is null, the provider creates a new customer.
		 */
		public ?string $customerReference;

		/**
		 * @param string $paymentModule Identifier of the payment module/provider to use (e.g. 'mollie', 'stripe').
		 * @param int $amount The amount to charge in minor units (e.g. 999 for €9.99).
		 * @param string $currency ISO 4217 currency code (EUR, USD, ...).
		 * @param string $description Human-readable description shown to the customer on the payment page.
		 * @param string|null $reference Merchant-assigned order reference passed to the provider (e.g. as invoice number or order ID).
		 * @param string|null $issuerId Provider-specific issuer identifier (e.g. a specific bank for iDEAL).
		 * @param string|null $webhookUrl URL the provider calls with asynchronous payment status updates.
		 * @param string|null $redirectUrl URL the customer is sent to after a successful payment.
		 * @param string|null $cancelUrl URL the customer is sent to if they cancel or abandon the payment.
		 * @param array<string, mixed> $metadata Arbitrary key/value pairs passed through to the provider unchanged.
		 * @param PaymentAddress|null $billingAddress Billing address associated with this payment.
		 * @param PaymentAddress|null $shippingAddress Physical delivery address for this order, if applicable.
		 * @param SequenceType|null $sequenceType Marks this as a one-off, first (mandate-creating), or recurring payment. Null behaves as OneOff.
		 * @param string|null $customerReference Existing provider customer id to attach this payment to. When
		 *                                       $sequenceType is First and this is null, the provider creates a new customer.
		 */
		public function __construct(
			string          $paymentModule,
			int             $amount,
			string          $currency,
			string          $description,
			?string         $reference = null,
			?string         $issuerId = null,
			?string         $webhookUrl = null,
			?string         $redirectUrl = null,
			?string         $cancelUrl = null,
			array           $metadata = [],
			?PaymentAddress $billingAddress = null,
			?PaymentAddress $shippingAddress = null,
			?SequenceType   $sequenceType = null,
			?string         $customerReference = null,
		) {
			$this->paymentModule = $paymentModule;
			$this->amount = $amount;
			$this->currency = $currency;
			$this->description = $description;
			$this->reference = $reference;
			$this->issuerId = $issuerId;
			$this->webhookUrl = $webhookUrl;
			$this->redirectUrl = $redirectUrl;
			$this->cancelUrl = $cancelUrl;
			$this->metadata = $metadata;
			$this->billingAddress = $billingAddress;
			$this->shippingAddress = $shippingAddress;
			$this->sequenceType = $sequenceType;
			$this->customerReference = $customerReference;
		}
	}