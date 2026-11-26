<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * Immutable snapshot of a single refund deducted from a settlement, returned by
	 * {@see PaymentProviderInterface::getSettlementRefunds()}.
	 */
	final readonly class SettlementRefundInfo {

		/** The provider that owns this settlement (e.g. 'mollie') */
		public string $provider;

		/** The settlement this refund was deducted from */
		public string $settlementId;

		/** The provider's unique identifier for the refund itself */
		public string $refundId;

		/** The provider's identifier of the payment this refund was made for */
		public string $paymentReference;

		/** Amount refunded to the customer, in the smallest currency unit, always positive */
		public int $amount;

		/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
		public string $currency;

		/** Refund description as recorded by the provider; empty when it has none */
		public string $description;

		/** The provider's raw refund status (for Mollie settlement refunds: 'refunded' or 'failed') */
		public string $status;

		/** When the refund was created */
		public ?\DateTimeImmutable $createdAt;

		/** @var array<string, mixed> Additional provider-specific metadata attached to the refund */
		public array $metadata;

		/**
		 * Creates a settlement refund snapshot.
		 * @param string $provider The provider that owns this settlement (e.g. 'mollie').
		 * @param string $settlementId The settlement this refund was deducted from.
		 * @param string $refundId The provider's unique identifier for the refund.
		 * @param string $paymentReference The provider's identifier of the refunded payment.
		 * @param int $amount Amount refunded, in the smallest currency unit, always positive.
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR', 'USD').
		 * @param string $description Refund description; empty when the provider has none.
		 * @param string $status The provider's raw refund status.
		 * @param ?\DateTimeImmutable $createdAt When the refund was created.
		 * @param array<string, mixed> $metadata Additional provider-specific metadata attached to the refund.
		 */
		public function __construct(
			string              $provider,
			string              $settlementId,
			string              $refundId,
			string              $paymentReference,
			int                 $amount,
			string              $currency,
			string              $description,
			string              $status,
			?\DateTimeImmutable $createdAt = null,
			array               $metadata = [],
		) {
			$this->provider = $provider;
			$this->settlementId = $settlementId;
			$this->refundId = $refundId;
			$this->paymentReference = $paymentReference;
			$this->amount = $amount;
			$this->currency = $currency;
			$this->description = $description;
			$this->status = $status;
			$this->createdAt = $createdAt;
			$this->metadata = $metadata;
		}
	}
