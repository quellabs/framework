<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Immutable result of a refund operation, returned by PaymentProviderInterface::refund()
	 * and used within getRefunds() response lists.
	 * All monetary values are in the smallest currency unit (e.g. cents).
	 */
	final readonly class RefundResult {
		
		public function __construct(
			/** The provider that processed this refund (e.g. 'mollie', 'stripe') */
			public string $provider,
			
			/** The provider's unique identifier for the capture */
			public string $paymentReference,
			
			/** The provider's unique identifier for this specific refund */
			public string $refundId,
			
			/** The refunded amount, in the smallest currency unit. Null if unknown */
			public ?int    $value,
			
			/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
			public string $currency,
			
			/** Attached provider specific metadata */
			public array $metadata = [],
			
		) {
		}
	}