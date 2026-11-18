<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Immutable result of a refund operation, returned by PaymentProviderInterface::refund()
	 * and used within getRefunds() response lists.
	 * All monetary values are in the smallest currency unit (e.g. cents).
	 */
	final readonly class RefundResult {

		/** The provider that processed this refund (e.g. 'mollie', 'stripe') */
		public string $provider;

		/** The provider's unique identifier for the capture */
		public string $paymentReference;

		/** The provider's unique identifier for this specific refund */
		public string $refundId;

		/** The refunded amount, in the smallest currency unit. Null if unknown */
		public ?int $value;

		/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
		public string $currency;

		/** @var array<string, mixed> Attached provider specific metadata */
		public array $metadata;

		/**
		 * @param string $provider The provider that processed this refund (e.g. 'mollie', 'stripe')
		 * @param string $paymentReference The provider's unique identifier for the capture
		 * @param string $refundId The provider's unique identifier for this specific refund
		 * @param ?int $value The refunded amount, in the smallest currency unit. Null if unknown
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR', 'USD')
		 * @param array<string, mixed> $metadata Attached provider specific metadata
		 */
		public function __construct(
			string $provider,
			string $paymentReference,
			string $refundId,
			?int    $value,
			string $currency,
			array $metadata = [],
		) {
			$this->provider = $provider;
			$this->paymentReference = $paymentReference;
			$this->refundId = $refundId;
			$this->value = $value;
			$this->currency = $currency;
			$this->metadata = $metadata;
		}
	}