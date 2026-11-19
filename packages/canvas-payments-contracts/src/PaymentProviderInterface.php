<?php
	
	namespace Quellabs\Payments\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
	/**
	 * Common interface for payment provider implementations.
	 * Each provider package (e.g. Mollie, Stripe) must implement this interface
	 * and register itself via composer metadata for automatic discovery by PaymentRouter.
	 *
	 * This is the single-provider counterpart to PaymentInterface: methods here are called on an
	 * already-resolved driver, so — unlike their PaymentInterface equivalents — exchange(),
	 * getRefunds(), getMandates(), and revokeMandate() take no `$driver` selector.
	 *
	 * Deliberately does not extend PaymentInterface: PaymentInterface's exchange/getRefunds/
	 * getMandates/revokeMandate carry a leading `$driver` argument that a single provider's own
	 * implementation has no use for, so the shared methods are redeclared here instead of inherited.
	 *
	 * @phpstan-import-type IssuerOption from PaymentInterface
	 * @phpstan-type ProviderMetadata array{driver: string, modules: list<string>}
	 */
	interface PaymentProviderInterface extends ProviderInterface {

		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Narrows ProviderInterface::getMetadata()'s generic shape for payment providers.
		 * @return ProviderMetadata
		 */
		public static function getMetadata(): array;

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
		 * Fetch the current state of a payment from the provider.
		 * Typically called from a webhook handler when the provider reports a status change.
		 * @param string $transactionId
		 * @param array<string, mixed> $extraData
		 * @return PaymentState
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState;
		
		/**
		 * Returns all refunds issued for the given transaction.
		 * @param string $paymentReference
		 * @return array<int, RefundResult>
		 */
		public function getRefunds(string $paymentReference): array;

		/**
		 * Returns all mandates registered for the given customer.
		 * @param string $customerReference
		 * @return array<int, MandateInfo>
		 */
		public function getMandates(string $customerReference): array;

		/**
		 * Revokes a mandate, preventing any further recurring charges against it.
		 * @param string $customerReference
		 * @param string $mandateId
		 * @return void
		 */
		public function revokeMandate(string $customerReference, string $mandateId): void;

		/**
		 * Returns settlement/payout batch information.
		 * @param string $settlementId Provider-specific settlement identifier, or an
		 *                             equivalent lookup key (e.g. Mollie accepts its own
		 *                             id or the bank-statement reference string).
		 * @return SettlementInfo
		 */
		public function getSettlement(string $settlementId): SettlementInfo;

		/**
		 * Returns the individual payments included in a settlement.
		 * @param string $settlementId
		 * @return array<int, SettlementPaymentInfo>
		 */
		public function getSettlementPayments(string $settlementId): array;

		/**
		 * Returns one page of the organization's settlements. Pass the previous
		 * call's SettlementListResult::$nextCursor to fetch the next page, or
		 * null to start from the first page. Sort order is provider-specific
		 * and not guaranteed by this contract (unconfirmed for Mollie today —
		 * do not assume newest-first or oldest-first).
		 * @param ?string $cursor Opaque pagination cursor, or null for the first page.
		 * @param int $limit Maximum settlements to return in this page (provider-specific upper bound).
		 * @return SettlementListResult
		 */
		public function listSettlements(?string $cursor = null, int $limit = 50): SettlementListResult;

	}