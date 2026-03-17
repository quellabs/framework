<?php
	
	namespace Quellabs\Payments\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Symfony\Component\HttpFoundation\Response;
	
	/**
	 * Common interface for payment provider implementations.
	 * Each provider package (e.g. Mollie, Stripe) must implement this interface
	 * and register itself via composer metadata for automatic discovery by PaymentRouter.
	 *
	 * Implementations must have a no-argument constructor. All configuration is
	 * supplied via setConfig() after instantiation by the discovery system.
	 */
	interface PaymentProviderInterface extends ProviderInterface {
		
		/**
		 * Initiate a new payment session and return a redirect URL for the customer.
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 */
		public function initiate(PaymentRequest $request): InitiateResult;
		
		/**
		 * Issue a refund for a previously completed payment.
		 * @param RefundRequest $refundRequest
		 * @return RefundResult
		 */
		public function refund(RefundRequest $refundRequest): RefundResult;
		
		/**
		 * Fetch the current state of a payment from the provider.
		 * Typically called from a webhook handler when the provider reports a status change.
		 * @param string $transactionId
		 * @param array $extraData
		 * @return PaymentState
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState;
		
		/**
		 * Returns available options for the given payment module.
		 * Used for modules that expose issuer or bank selection (e.g. iDEAL, KBC, gift cards).
		 * Returns an empty array for modules with no selectable options.
		 * @param string $paymentModule
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array;
		
		/**
		 * Returns all refunds issued for the given transaction.
		 * @param string $transactionId
		 * @return array
		 */
		public function getRefunds(string $transactionId): array;
		
	}