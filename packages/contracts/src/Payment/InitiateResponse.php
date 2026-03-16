<?php
	
	namespace Quellabs\Contracts\Payment;
	
	/**
	 * Immutable value object returned after successfully initiating a payment session.
	 * Contains the data the caller needs to redirect the customer to the payment page.
	 */
	final readonly class InitiateResponse {
		
		/**
		 * @param string $provider      Identifier of the payment provider that created this session (e.g. 'mollie', 'stripe').
		 * @param string $transactionId Provider-assigned transaction ID, used for status checks and refunds.
		 * @param string $redirectUrl   URL to redirect the customer to in order to complete the payment.
		 */
		public function __construct(
			public string $provider,
			public string $transactionId,
			public string $redirectUrl,
		) {
		}
	}