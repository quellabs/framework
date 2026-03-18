<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Immutable snapshot of a payment's current state, returned by PaymentProviderInterface::exchange().
	 * All monetary values are in the smallest currency unit (e.g. cents).
	 */
	final readonly class PaymentState {
		
		public function __construct(
			/** The provider that owns this payment (e.g. 'mollie', 'stripe') */
			public string        $provider,
			
			/** The provider's unique identifier for this payment */
			public string        $transactionId,
			
			/** Normalized payment status mapped from the provider's internal state */
			public PaymentStatus $state,
			
			/** The total amount refunded so far, in the smallest currency unit */
			public int           $valueRefunded,
			
			/** The amount still eligible for refund, in the smallest currency unit */
			public int           $valueRefundable,
			
			/** The raw status string as returned by the provider, before normalization */
			public ?string       $internalState = null,
			
			/** ISO 4217 currency code (e.g. 'EUR', 'USD') */
			public ?string       $currency = null,
			
			/** Additional provider-specific metadata attached to the payment */
			public array         $metadata = [],
			
		) {
		}
	}