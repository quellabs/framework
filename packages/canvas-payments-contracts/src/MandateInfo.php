<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * Immutable snapshot of a customer mandate, authorizing off-session recurring charges.
	 * Returned by {@see PaymentProviderInterface::getMandates()}.
	 */
	final readonly class MandateInfo {

		/** The provider that owns this mandate (e.g. 'mollie') */
		public string $provider;

		/** The provider's unique identifier for this mandate */
		public string $mandateId;

		/** The provider's customer identifier this mandate belongs to */
		public string $customerReference;

		/** Normalized mandate status mapped from the provider's internal state */
		public MandateStatus $status;

		/** Payment method the mandate was created for (e.g. 'directdebit', 'creditcard') */
		public string $method;

		/** Raw status string returned by the provider, before normalization */
		public ?string $internalState;

		/** @var array<string, mixed> Additional provider-specific metadata attached to the mandate */
		public array $metadata;

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
			string        $provider,
			string        $mandateId,
			string        $customerReference,
			MandateStatus $status,
			string        $method,
			?string       $internalState = null,
			array         $metadata = [],
		) {
			$this->provider = $provider;
			$this->mandateId = $mandateId;
			$this->customerReference = $customerReference;
			$this->status = $status;
			$this->method = $method;
			$this->internalState = $internalState;
			$this->metadata = $metadata;
		}
	}
