<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * Immutable snapshot of a settlement/payout batch, returned by
	 * {@see PaymentProviderInterface::getSettlement()}.
	 */
	final readonly class SettlementInfo {

		/** The provider that owns this settlement (e.g. 'mollie') */
		public string $provider;

		/** The provider's unique identifier for this settlement */
		public string $settlementId;

		/** The bank-statement reference string for this settlement/payout */
		public string $reference;

		/** Normalized settlement status mapped from the provider's internal state */
		public SettlementStatus $status;

		/** Total settlement amount, in the smallest currency unit */
		public int $amount;

		/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
		public string $currency;

		/** When the settlement was paid out. Null while still open/pending */
		public ?\DateTimeImmutable $settledAt;

		/** Raw status string returned by the provider, before normalization */
		public ?string $internalState;

		/** @var array<string, mixed> Additional provider-specific metadata attached to the settlement */
		public array $metadata;

		/**
		 * @param string $provider The provider that owns this settlement (e.g. 'mollie').
		 * @param string $settlementId The provider's unique identifier for this settlement.
		 * @param string $reference The bank-statement reference string for this settlement/payout.
		 * @param SettlementStatus $status Normalized settlement status mapped from the provider's internal state.
		 * @param int $amount Total settlement amount, in the smallest currency unit.
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR', 'USD').
		 * @param ?\DateTimeImmutable $settledAt When the settlement was paid out. Null while still open/pending.
		 * @param ?string $internalState Raw status string returned by the provider, before normalization.
		 * @param array<string, mixed> $metadata Additional provider-specific metadata attached to the settlement.
		 */
		public function __construct(
			string              $provider,
			string              $settlementId,
			string              $reference,
			SettlementStatus    $status,
			int                 $amount,
			string              $currency,
			?\DateTimeImmutable $settledAt = null,
			?string             $internalState = null,
			array               $metadata = [],
		) {
			$this->provider = $provider;
			$this->settlementId = $settlementId;
			$this->reference = $reference;
			$this->status = $status;
			$this->amount = $amount;
			$this->currency = $currency;
			$this->settledAt = $settledAt;
			$this->internalState = $internalState;
			$this->metadata = $metadata;
		}
	}
