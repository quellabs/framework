<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * Immutable snapshot of a customer mandate, authorizing off-session recurring charges.
	 * Returned by {@see PaymentProviderInterface::getMandates()}.
	 */
	final readonly class MandateInfo {

		/**
		 * @param string $provider The provider that owns this mandate (e.g. 'mollie').
		 * @param string $mandateId The provider's unique identifier for this mandate.
		 * @param string $customerReference The provider's customer identifier this mandate belongs to.
		 * @param MandateStatus $status Normalized mandate status mapped from the provider's internal state.
		 * @param string $method Payment method the mandate was created for (e.g. 'directdebit', 'creditcard').
		 * @param ?string $internalState Raw status string returned by the provider, before normalization.
		 * @param array<string, mixed> $metadata Additional provider-specific metadata attached to the mandate.
		 */
		public function __construct(
			public string        $provider,
			public string        $mandateId,
			public string        $customerReference,
			public MandateStatus $status,
			public string        $method,
			public ?string       $internalState = null,
			public array         $metadata = [],
		) {
		}
	}
