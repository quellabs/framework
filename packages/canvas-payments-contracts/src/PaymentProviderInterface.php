<?php
	
	namespace Quellabs\Payments\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
	/**
	 * Common interface for payment provider implementations.
	 * Each provider package (e.g. Mollie, Stripe) must implement this interface
	 * and register itself via composer metadata for automatic discovery by PaymentRouter.
	 */
	interface PaymentProviderInterface extends ProviderInterface {
		
		/**
		 * Initiate a new payment session and return a redirect URL for the customer.
		 * @param PaymentRequest $request
		 * @return PaymentResponse
		 */
		public function initiate(PaymentRequest $request): PaymentResponse;
		
		/**
		 * Issue a refund for a previously completed payment.
		 * @param RefundRequest $refundRequest
		 * @return PaymentResponse
		 */
		public function refund(RefundRequest $refundRequest): PaymentResponse;
		
		/**
		 * Fetch the current state of a payment from the provider.
		 * Typically called from a webhook handler when the provider reports a status change.
		 * @param string $transactionId
		 * @param array $extraData
		 * @return PaymentResponse
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentResponse;
		
		/**
		 * Returns available options for the given payment module.
		 * Used for modules that expose issuer or bank selection (e.g. iDEAL, KBC, gift cards).
		 * Returns an empty array for modules with no selectable options.
		 * @param string $paymentModule
		 * @return PaymentResponse
		 */
		public function getPaymentOptions(string $paymentModule): PaymentResponse;
		
		/**
		 * Returns all refunds issued for the given transaction.
		 * @param string $transactionId
		 * @return PaymentResponse
		 */
		public function getRefunds(string $transactionId): PaymentResponse;
		
	}