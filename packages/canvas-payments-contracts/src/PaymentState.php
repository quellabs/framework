<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Immutable snapshot of a payment's current state.
	 *
	 * Returned by {@see PaymentProviderInterface::exchange()} after querying a provider.
	 * All monetary values are in the smallest currency unit (e.g. cents for EUR/USD).
	 *
	 * The hash produced by {@see getHash()} is stable across calls for the same state,
	 * making it suitable for change-detection (e.g. dirty-checking before persistence).
	 */
	final readonly class PaymentState {

		/** The provider that owns this payment (e.g. 'mollie', 'stripe') */
		public string $provider;

		/** The provider's unique identifier for this payment */
		public string $transactionId;

		/** Normalized payment status mapped from the provider's internal state */
		public PaymentStatus $state;

		/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
		public string $currency;

		/** Total amount paid in the smallest currency unit, or null if unknown */
		public ?int $valuePaid;

		/** Total amount refunded so far, in the smallest currency unit */
		public int $valueRefunded;

		/** Raw status string returned by the provider, before normalization */
		public ?string $internalState;

		/** @var array<string, mixed> Additional provider-specific metadata attached to the payment */
		public array $metadata;

		/** The provider's own failure reason for a non-successful payment, or null if not applicable/unknown */
		public ?string $failureReason;

		/** The mandate id this payment was charged against, or null if not a mandate-based charge/unknown */
		public ?string $mandateId;

		/** Total amount charged back by the customer's bank so far, in the smallest currency unit; 0 when none or unknown */
		public int $valueChargedBack;

		/**
		 * @param string $provider The provider that owns this payment (e.g. 'mollie', 'stripe').
		 * @param string $transactionId The provider's unique identifier for this payment.
		 * @param PaymentStatus $state Normalized payment status mapped from the provider's internal state.
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR', 'USD').
		 * @param ?int $valuePaid Total amount paid in the smallest currency unit, or null if unknown.
		 * @param int $valueRefunded Total amount refunded so far, in the smallest currency unit.
		 * @param ?string $internalState Raw status string returned by the provider, before normalization.
		 * @param array<string, mixed> $metadata Additional provider-specific metadata attached to the payment.
		 * @param ?string $failureReason The provider's own failure reason for a non-successful payment, or null if not applicable/unknown.
		 * @param ?string $mandateId The mandate id this payment was charged against, or null if not a mandate-based charge/unknown.
		 * @param int $valueChargedBack Total amount charged back so far, in the smallest currency unit; 0 when none or the provider does not report it.
		 */
		public function __construct(
			string        $provider,
			string        $transactionId,
			PaymentStatus $state,
			string        $currency,
			?int          $valuePaid = null,
			int           $valueRefunded = 0,
			?string       $internalState = null,
			array         $metadata = [],
			?string       $failureReason = null,
			?string       $mandateId = null,
			int           $valueChargedBack = 0,
		) {
			$this->provider = $provider;
			$this->transactionId = $transactionId;
			$this->state = $state;
			$this->currency = $currency;
			$this->valuePaid = $valuePaid;
			$this->valueRefunded = $valueRefunded;
			$this->internalState = $internalState;
			$this->metadata = $metadata;
			$this->failureReason = $failureReason;
			$this->mandateId = $mandateId;
			$this->valueChargedBack = $valueChargedBack;
		}
		
		/**
		 * Returns a SHA-256 hash of the payment state, stable across calls for identical data.
		 * Useful for change-detection: compare hashes before and after an exchange() call
		 * to determine whether the payment state has actually changed.
		 * @throws \JsonException If state serialization fails.
		 */
		public function getHash(): string {
			$payload = json_encode($this->toStableArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			return hash('sha256', $payload);
		}
		
		/**
		 * Builds a deterministic array representation of the payment state.
		 * @return array<string, mixed>
		 */
		private function toStableArray(): array {
			return [
				'provider'         => $this->provider,
				'transactionId'    => $this->transactionId,
				'state'            => $this->state->value,
				'currency'         => $this->currency,
				'valuePaid'        => $this->valuePaid,
				'valueRefunded'    => $this->valueRefunded,
				'internalState'    => $this->internalState,
				'metadata'         => $this->normalizeMeta($this->metadata),
				'failureReason'    => $this->failureReason,
				'mandateId'        => $this->mandateId,
				'valueChargedBack' => $this->valueChargedBack
			];
		}
		
		/**
		 * Recursively sorts array keys to guarantee a stable key order for hashing.
		 * @param array<string|int, mixed> $data
		 * @return array<string|int, mixed>
		 */
		private function normalizeMeta(array $data): array {
			ksort($data);
			
			foreach ($data as $key => $value) {
				if (is_array($value)) {
					$data[$key] = $this->normalizeMeta($value);
				}
			}
			
			return $data;
		}
	}