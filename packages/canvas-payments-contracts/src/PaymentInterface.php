<?php
	
	namespace Quellabs\Payments\Contracts;
	
	/**
	 * Public-facing interface for application code. This is the type application code should
	 * inject via DI — it is bound to PaymentRouter, which discovers and routes to whichever
	 * provider packages (e.g. Mollie, Stripe) are installed.
	 *
	 * Operations that only make sense against a single already-known payment (initiate, refund,
	 * chargeRecurring, getPaymentOptions) are routed implicitly via the module name carried on
	 * the request/argument. Operations that need an explicit provider selector because there is
	 * no payment/request to infer it from (exchange, getRefunds, getMandates, revokeMandate) take
	 * a leading `$driver` argument identifying which registered provider to use — see
	 * PaymentProviderInterface, which is the narrower, single-provider contract that driver
	 * packages implement (without the `$driver` selector, since a driver is already provider-specific).
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

		/**
		 * Fetch the current state of a payment for reconciliation of missed webhooks.
		 * @param string $driver Driver name as returned in PaymentState::$provider (e.g. 'mollie')
		 * @param string $transactionId Provider-assigned transaction ID from InitiateResult
		 * @param array<string, mixed> $extraData Optional additional data to pass to the provider
		 * @return PaymentState
		 */
		public function exchange(string $driver, string $transactionId, array $extraData = []): PaymentState;

		/**
		 * Returns all refunds issued for the given transaction.
		 * @param string $driver Driver name as returned in PaymentState::$provider (e.g. 'mollie')
		 * @param string $paymentReference
		 * @return array<int, RefundResult>
		 */
		public function getRefunds(string $driver, string $paymentReference): array;

		/**
		 * Returns all mandates registered for the given customer.
		 * @param string $driver Driver name as returned in PaymentState::$provider (e.g. 'mollie')
		 * @param string $customerReference
		 * @return array<int, MandateInfo>
		 */
		public function getMandates(string $driver, string $customerReference): array;

		/**
		 * Revokes a mandate, preventing any further recurring charges against it.
		 * @param string $driver Driver name as returned in PaymentState::$provider (e.g. 'mollie')
		 * @param string $customerReference
		 * @param string $mandateId
		 * @return void
		 */
		public function revokeMandate(string $driver, string $customerReference, string $mandateId): void;

	}