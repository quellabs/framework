<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * Immutable snapshot of a single chargeback deducted from a settlement, returned by
	 * {@see PaymentProviderInterface::getSettlementChargebacks()}.
	 */
	final readonly class SettlementChargebackInfo {

		/** The provider that owns this settlement (e.g. 'mollie') */
		public string $provider;

		/** The settlement this chargeback was deducted from */
		public string $settlementId;

		/** The provider's unique identifier for the chargeback itself */
		public string $chargebackId;

		/** The provider's identifier of the payment that was charged back */
		public string $paymentReference;

		/** Amount charged back by the customer, in the smallest currency unit, always positive */
		public int $amount;

		/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
		public string $currency;

		/** The bank's reason code (e.g. SEPA 'AC01'), or null when the provider gives none */
		public ?string $reasonCode;

		/** Human-readable reason, or null when the provider gives none */
		public ?string $reasonDescription;

		/** When the chargeback was created */
		public ?\DateTimeImmutable $createdAt;

		/** When the chargeback was reversed, or null when it was not */
		public ?\DateTimeImmutable $reversedAt;

		/** @var array<string, mixed> Additional provider-specific metadata */
		public array $metadata;

		/**
		 * @param string $provider The provider that owns this settlement (e.g. 'mollie').
		 * @param string $settlementId The settlement this chargeback was deducted from.
		 * @param string $chargebackId The provider's unique identifier for the chargeback.
		 * @param string $paymentReference The provider's identifier of the charged-back payment.
		 * @param int $amount Amount charged back, in the smallest currency unit, always positive.
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR', 'USD').
		 * @param ?string $reasonCode The bank's reason code, or null.
		 * @param ?string $reasonDescription Human-readable reason, or null.
		 * @param ?\DateTimeImmutable $createdAt When the chargeback was created.
		 * @param ?\DateTimeImmutable $reversedAt When the chargeback was reversed, or null.
		 * @param array<string, mixed> $metadata Additional provider-specific metadata.
		 */
		public function __construct(
			string              $provider,
			string              $settlementId,
			string              $chargebackId,
			string              $paymentReference,
			int                 $amount,
			string              $currency,
			?string             $reasonCode = null,
			?string             $reasonDescription = null,
			?\DateTimeImmutable $createdAt = null,
			?\DateTimeImmutable $reversedAt = null,
			array               $metadata = [],
		) {
			$this->provider = $provider;
			$this->settlementId = $settlementId;
			$this->chargebackId = $chargebackId;
			$this->paymentReference = $paymentReference;
			$this->amount = $amount;
			$this->currency = $currency;
			$this->reasonCode = $reasonCode;
			$this->reasonDescription = $reasonDescription;
			$this->createdAt = $createdAt;
			$this->reversedAt = $reversedAt;
			$this->metadata = $metadata;
		}
	}
