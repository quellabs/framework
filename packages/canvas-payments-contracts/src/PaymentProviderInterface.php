<?php
	
	namespace Quellabs\Payments\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Symfony\Component\HttpFoundation\Response;
	
	/**
	 * Common interface for payment provider implementations.
	 * Each provider package (e.g. Mollie, Stripe) must implement this interface
	 * and register itself via composer metadata for automatic discovery by PaymentRouter.
	 */
	interface PaymentProviderInterface extends PaymentInterface, ProviderInterface {
		
		/**
		 * Fetch the current state of a payment from the provider.
		 * Typically called from a webhook handler when the provider reports a status change.
		 * @param string $transactionId
		 * @param array $extraData
		 * @return PaymentState
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState;
		
		/**
		 * Returns all refunds issued for the given transaction.
		 * @param string $paymentReference
		 * @return array
		 */
		public function getRefunds(string $paymentReference): array;

	}