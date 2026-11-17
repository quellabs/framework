<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * Immutable snapshot of a single payment included in a settlement, returned by
	 * {@see PaymentProviderInterface::getSettlementPayments()}.
	 */
	final readonly class SettlementPaymentInfo {

		/** The provider that owns this settlement (e.g. 'mollie') */
		public string $provider;

		/** The settlement this payment was paid out in */
		public string $settlementId;

		/** The provider's unique identifier for the underlying payment */
		public string $paymentReference;

		/** Gross payment amount, in the smallest currency unit, before provider fees */
		public int $amount;

		/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
		public string $currency;

		/** Payment description as recorded by the provider */
		public string $description;

		/** When the underlying payment was created */
		public ?\DateTimeImmutable $createdAt;

		/** @var array<string, mixed> Additional provider-specific metadata attached to the payment */
		public array $metadata;

		/**
		 * @param string $provider The provider that owns this settlement (e.g. 'mollie').
		 * @param string $settlementId The settlement this payment was paid out in.
		 * @param string $paymentReference The provider's unique identifier for the underlying payment.
		 * @param int $amount Gross payment amount, in the smallest currency unit, before provider fees.
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR', 'USD').
		 * @param string $description Payment description as recorded by the provider.
		 * @param ?\DateTimeImmutable $createdAt When the underlying payment was created.
		 * @param array<string, mixed> $metadata Additional provider-specific metadata attached to the payment.
		 */
		public function __construct(
			string              $provider,
			string              $settlementId,
			string              $paymentReference,
			int                 $amount,
			string              $currency,
			string              $description,
			?\DateTimeImmutable $createdAt = null,
			array               $metadata = [],
		) {
			$this->provider = $provider;
			$this->settlementId = $settlementId;
			$this->paymentReference = $paymentReference;
			$this->amount = $amount;
			$this->currency = $currency;
			$this->description = $description;
			$this->createdAt = $createdAt;
			$this->metadata = $metadata;
		}
	}
