<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Immutable value object returned after successfully initiating a payment session.
	 * Contains the data the caller needs to redirect the customer to the payment page.
	 *
	 * @phpstan-type InitiateResultArray array{
	 *      provider: string,
	 *      transaction_id: string,
	 *      redirect_url: ?string,
	 *      customer_reference: ?string,
	 *      metadata: array<string, mixed>
	 *  }
	 */
	final readonly class InitiateResult {

		/**
		 * InitiateResponse constructor
		 * @param string $provider Identifier of the payment provider that created this session (e.g. 'mollie', 'stripe').
		 * @param string $transactionId Provider-assigned transaction ID, used for status checks and refunds.
		 * @param ?string $redirectUrl URL to redirect the customer to in order to complete the payment. Null for
		 *                            recurring charges, which never produce a checkout redirect.
		 * @param array<string, mixed> $metadata Optional provider-specific metadata.
		 * @param ?string $customerReference Provider-assigned customer id, populated when a customer was
		 *                                   created or used for this payment (mainly First/Recurring).
		 */
		public function __construct(
			public string  $provider,
			public string  $transactionId,
			public ?string $redirectUrl = null,
			public array   $metadata = [],
			public ?string $customerReference = null,
		) {
		}

		/**
		 * Convert contents to array
		 * @return InitiateResultArray
		 */
		public function toArray(): array {
			return [
				'provider'            => $this->provider,
				'transaction_id'      => $this->transactionId,
				'redirect_url'        => $this->redirectUrl,
				'customer_reference'  => $this->customerReference,
				'metadata'            => $this->metadata,
			];
		}
	}