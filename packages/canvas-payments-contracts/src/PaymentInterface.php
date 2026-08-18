<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Common interface for payment provider implementations.
	 * Each provider package (e.g. Mollie, Stripe) must implement this interface
	 * and register itself via composer metadata for automatic discovery by PaymentRouter.
	 *
	 * Implementations must have a no-argument constructor. All configuration is
	 * supplied via setConfig() after instantiation by the discovery system.
	 *
	 * @phpstan-type IssuerOption array{
	 *     id: string,
	 *     name: string,
	 *     issuerId: string,
	 *     swift: string,
	 *     icon: string
	 * }
	 */
	interface PaymentInterface {
		
		/**
		 * Initiate a new payment session and return a redirect URL for the customer.
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 */
		public function initiate(PaymentRequest $request): InitiateResult;
		
		/**
		 * Issue a refund for a previously completed payment.
		 * @param RefundRequest $request
		 * @return RefundResult
		 */
		public function refund(RefundRequest $request): RefundResult;

		/**
		 * Charge an existing mandate for a recurring, off-session payment. No customer interaction
		 * or redirect is involved — this is typically called from a scheduled job.
		 * @param RecurringChargeRequest $request
		 * @return InitiateResult
		 */
		public function chargeRecurring(RecurringChargeRequest $request): InitiateResult;
		
		/**
		 * Returns available options for the given payment module.
		 * Used for modules that expose issuer or bank selection (e.g. iDEAL, KBC, gift cards).
		 * Returns an empty array for modules with no selectable options.
		 * @param string $paymentModule
		 * @return array<int, IssuerOption>
		 */
		public function getPaymentOptions(string $paymentModule): array;
		
	}