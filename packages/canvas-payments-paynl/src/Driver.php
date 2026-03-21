<?php
	
	namespace Quellabs\Payments\PayNL;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentInitiationException;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Payments\Contracts\RefundResult;
	
	class Driver implements PaymentProviderInterface {
		
		/**
		 * Driver name
		 */
		const DRIVER_NAME = "paynl";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var PayNLGateway|null
		 */
		private ?PayNLGateway $gateway = null;
		
		/**
		 * Maps our internal module names to Pay.nl payment method IDs (integer option IDs).
		 *
		 * Pay.nl identifies payment methods by integer IDs, not string codes.
		 * The full list is documented at:
		 * @see https://developer.pay.nl/docs/payment-option-ids-subids
		 *
		 * Note: PayPal is not in Pay.nl's standard payment option list — it is not offered
		 * as an e-commerce method. Giropay (694) is marked deprecated by Pay.nl as of 2024.
		 * SOFORT is not available as a standalone method; use Trustly (2718) for bank transfers.
		 */
		private const MODULE_TYPE_MAP = [
			'paynl_ideal'        => 10,
			'paynl_bancontact'   => 436,
			'paynl_creditcard'   => 706,   // Visa + Mastercard combined
			'paynl_visa'         => 3141,
			'paynl_mastercard'   => 3138,
			'paynl_amex'         => 1705,
			'paynl_applepay'     => 2277,
			'paynl_googlepay'    => 2558,
			'paynl_klarna'       => 1717,
			'paynl_in3'          => 1813,
			'paynl_riverty'      => 2561,  // Riverty (formerly AfterPay NL)
			'paynl_eps'          => 2062,
			'paynl_trustly'      => 2718,
			'paynl_paybybank'    => 2970,
		];
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_TYPE_MAP),
			];
		}
		
		/**
		 * Returns the active configuration for this provider instance.
		 * Merges stored config over the defaults so only explicitly set keys override.
		 * @return array
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this provider instance.
		 * Called by the discovery system after instantiation, before any other methods are invoked.
		 * @param array $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this provider.
		 * Merged with loaded config files during discovery — values from config files take precedence.
		 * @return array
		 */
		public function getDefaults(): array {
			return [
				'test_mode'         => false,
				'token_code'        => '',   // AT-xxxx-xxxx from Pay.nl dashboard
				'api_token'         => '',   // 40-character hash from Pay.nl dashboard
				'service_id'        => '',   // SL-xxxx-xxxx sales location ID
				'return_url'        => '',
				'cancel_return_url' => '',
				'exchange_url'      => '',
				'default_currency'  => 'EUR',
			];
		}
		
		/**
		 * Returns the normalized issuer list for the given payment module.
		 *
		 * Pay.nl does not support bank pre-selection for iDEAL. Direct issuer redirect
		 * was part of iDEAL 1.0 and was permanently discontinued on 31-12-2024. Under
		 * iDEAL 2.0, bank selection always happens on the hosted payment page — there is
		 * no issuer list to fetch and no subId to pass when creating an order.
		 *
		 * This method always returns an empty array for all payment modules.
		 *
		 * @see https://developer.pay.nl/docs/ideal
		 * @param string $paymentModule e.g. 'paynl_ideal'
		 * @return array Always empty — Pay.nl handles payment method UI on the hosted page
		 */
		public function getPaymentOptions(string $paymentModule): array {
			// Pay.nl removed direct issuer redirect in iDEAL 2.0 (deprecated 31-12-2024).
			// All payment method selection, including iDEAL bank picking, is handled on
			// the hosted checkout page. There is nothing to return here.
			return [];
		}
		
		/**
		 * Initiates a payment by creating a Pay.nl order via the TGU Order:Create API.
		 *
		 * Pay.nl returns a checkout URL (links.redirect) that the shopper is redirected to.
		 * After payment, Pay.nl redirects the shopper to returnUrl with the order UUID
		 * appended as ?id={uuid}&orderId={legacyOrderId}.
		 *
		 * The order UUID (id field) is the authoritative identifier for all subsequent
		 * status and refund calls. Store it as your transactionId.
		 *
		 * Test mode is passed per-order via integration.test=true in the request body.
		 *
		 * @see https://developer.pay.nl/reference/api_create_order-1
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			// Merge stored config with defaults to get the full configuration set.
			$config = $this->getConfig();
			
			// Resolve the Pay.nl integer payment method ID from the module name.
			// Every module name must be present in MODULE_OPTION_MAP before use.
			if (!isset(self::MODULE_TYPE_MAP[$request->paymentModule])) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, "Unknown payment module: '{$request->paymentModule}'");
			}
			
			// Convert payment module to internal PayNL id
			$paymentMethodId = self::MODULE_TYPE_MAP[$request->paymentModule];
			
			// Build the core order payload.
			// amount.value is in minor units (e.g. 1250 = €12.50).
			$payload = array_filter([
				'serviceId'     => $config['service_id'],
				'description'   => $request->description,
				'reference'     => $request->metadata['reference'] ?? null,
				'amount'        => [
					'value'    => $request->amount,
					'currency' => $request->currency,
				],
				'paymentMethod' => [
					'id' => $paymentMethodId,
				],
				'returnUrl'     => $config['return_url'],
				'exchangeUrl'   => $config['exchange_url'],
			], fn($v) => $v !== null);
			
			// Attach a customer block when billing address data is available.
			// Pay.nl uses this to pre-fill the hosted page and for risk scoring.
			// Only non-null values are included — empty strings are omitted.
			if ($request->billingAddress !== null) {
				$customer = array_filter([
					'email'   => $request->billingAddress->email ?: null,
					'country' => $request->billingAddress->country ?: null,
				], fn($v) => $v !== null);
				
				if (!empty($customer)) {
					$payload['customer'] = $customer;
				}
			}
			
			// Signal Pay.nl to use the test environment for this order.
			// Test mode is per-order, not per-endpoint — the same URL handles both.
			if ($config['test_mode']) {
				$payload['integration'] = ['test' => true];
			}
			
			// Send the order creation request to the Pay.nl API.
			$result = $this->getGateway()->createOrder($payload);
			
			// A result code of 0 means the API call failed; surface it as an exception.
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// The UUID (id) is the stable identifier used for all subsequent API calls.
			// orderId is a legacy human-readable reference — not used for API calls.
			return new InitiateResult(
				provider: self::DRIVER_NAME,
				transactionId: $result['response']['id'],
				redirectUrl: $result['response']['links']['redirect'],
			);
		}
		
		/**
		 * Resolves the payment state for a given transaction.
		 *
		 * Pay.nl uses a pull model: the exchange POST body and the return URL both carry
		 * only the order UUID and an action hint. The authoritative state must always be
		 * fetched via the Order:Status API — neither callback carries status directly.
		 *
		 * Return-URL flow:
		 *   Pay.nl appends ?id={uuid}&orderId={legacyId} to returnUrl. The controller
		 *   extracts 'id' (the UUID) and passes it as $transactionId.
		 *
		 * Exchange/webhook flow:
		 *   Pay.nl POSTs form data with 'order_id' (the UUID). The controller extracts it
		 *   and passes it as $transactionId.
		 *
		 * Both flows call the same API endpoint — this method is unified.
		 *
		 * Status codes reference:
		 *   100      = PAID
		 *   20       = PENDING (INIT or waiting for payment)
		 *   50       = PENDING (data received, waiting for processor)
		 *   90       = PENDING (data sent to payer, awaiting bank confirmation)
		 *   95       = AUTHORIZE (authorised, not yet captured — treat as Pending)
		 *   -60      = FAILURE
		 *   -63/-64  = DENIED
		 *   -80      = EXPIRED
		 *   -90      = CANCEL
		 *   -71      = CHARGEBACK (treat as Failed)
		 *   -81      = REFUND (full refund)
		 *   -82      = PARTIAL REFUND
		 *
		 * @see https://developer.pay.nl/docs/transaction-statuses
		 * @see https://developer.pay.nl/reference/api_get_status-1
		 * @param string $transactionId The Pay.nl order UUID (id from order create response)
		 * @param array $extraData action: 'return' | 'exchange' (informational only)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Fetch the current order status from the Pay.nl API.
			$result = $this->getGateway()->getOrderStatus($transactionId);
			
			// A result code of 0 means the API call failed; surface it as an exception.
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Unpack the top-level fields we need for state mapping.
			$data = $result['response'];
			$statusCode = (int)($data['status']['code'] ?? 20);
			$statusAction = strtoupper($data['status']['action'] ?? '');
			$currency = $data['amount']['currency'] ?? '';
			$amount = (int)($data['amount']['value'] ?? 0);
			
			// Map the numeric Pay.nl status code to our internal PaymentStatus enum.
			// Positive codes >= 100 are successful outcomes; negative codes are terminal
			// failures or post-payment events. Codes 20–99 are all in-progress states.
			$state = match (true) {
				$statusCode === 100 => PaymentStatus::Paid,
				$statusCode === -81 => PaymentStatus::Refunded,      // Full refund completed
				$statusCode === -82 => PaymentStatus::Paid,          // Partial refund — original payment still succeeded
				$statusCode === -90 => PaymentStatus::Canceled,      // Cancelled by the user
				$statusCode === -80 => PaymentStatus::Canceled,      // Authorisation or paylink expired
				$statusCode === -60 => PaymentStatus::Failed,        // Processing error at the payment method or issuer
				$statusCode === -63 || $statusCode === -64 => PaymentStatus::Failed,        // Declined by processor or by API call
				$statusCode === -71 => PaymentStatus::Failed,        // Chargeback received — funds reclaimed
				$statusCode >= 20 && $statusCode < 100 => PaymentStatus::Pending,       // Any in-progress state including AUTHORIZE (95)
				default => PaymentStatus::Pending,       // Unknown code — assume still in progress
			};
			
			// valuePaid reflects the original authorised amount for Paid and Refunded states.
			// For partial refund (-82) the original payment succeeded, so we still report the full amount.
			// For all other states (Pending, Canceled, Failed) the value is 0.
			$valuePaid = in_array($state, [PaymentStatus::Paid, PaymentStatus::Refunded], true) ? $amount : 0;
			
			// Sum the amounts of all refund-type payment entries embedded in the order.
			// Refunds appear alongside regular payment attempts in the payments[] array,
			// identified by their own negative status codes (-81 for full, -82 for partial).
			$valueRefunded = array_reduce($data['payments'] ?? [], function (int $carry, array $payment): int {
				$paymentStatusCode = (int)($payment['status']['code'] ?? 0);
				
				// Only accumulate payments that represent refund transactions.
				if ($paymentStatusCode === -81 || $paymentStatusCode === -82) {
					return $carry + (int)($payment['amount']['value'] ?? 0);
				}
				
				return $carry;
			}, 0);
			
			// Extract the payment method ID from the first payment entry that reached PAID (100).
			// There may be multiple entries if the shopper retried with a different method.
			$paymentMethodId = null;
			
			foreach ($data['payments'] ?? [] as $payment) {
				if ((int)($payment['status']['code'] ?? 0) === 100) {
					$paymentMethodId = $payment['paymentMethod']['id'] ?? null;
					break;
				}
			}
			
			// Return the payment state
			return new PaymentState(
				provider: self::DRIVER_NAME,
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $valueRefunded,
				internalState: $statusAction ?: (string)$statusCode,
				metadata: array_filter([
					'paymentMethodId' => $paymentMethodId,
					'orderId'         => $data['orderId'] ?? null,  // Legacy human-readable ID
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Refunds a previously completed payment.
		 *
		 * Pay.nl ecommerce refunds use PATCH /v1/orders/{uuid} with an amount object.
		 * Omitting the amount triggers a full refund; providing an amount triggers a partial refund.
		 * The amount must be in minor units (e.g. 500 = €5.00).
		 *
		 * Pay.nl processes the refund asynchronously — the order status changes to REFUND (-81)
		 * or PARTIAL REFUND (-82) once the bank confirms. The exchange URL receives a
		 * refund:add notification immediately and a refund:received notification on settlement.
		 *
		 * @see https://developer.pay.nl/docs/refund
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Build the refund payload.
			// Omitting 'amount' signals a full refund to Pay.nl.
			// Including it with a value in minor units signals a partial refund.
			$payload = array_filter([
				'description' => $request->description ?? '',
				'amount'      => $request->amount !== null ? [
					'value'    => $request->amount,
					'currency' => $request->currency,
				] : null,
			], fn($v) => $v !== null);
			
			// Send the refund request to the Pay.nl API.
			$result = $this->getGateway()->refundOrder($request->paymentReference, $payload);
			
			// A result code of 0 means the API call failed; surface it as an exception.
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Pay.nl returns the updated order object, not a discrete refund record.
			// We use the order UUID as the refundId since no separate refund transaction
			// ID is returned from the PATCH endpoint.
			$refundId = (string)($result['response']['id'] ?? $request->paymentReference);
			
			return new RefundResult(
				provider: self::DRIVER_NAME,
				paymentReference: $request->paymentReference,
				refundId: $refundId,
				value: $request->amount,
				currency: $request->currency,
			);
		}
		
		/**
		 * Returns a list of RefundResult objects for all refunds associated with a transaction.
		 *
		 * Pay.nl does not have a dedicated refund listing endpoint. Refund information is
		 * embedded in the order status response as payment entries with negative status codes:
		 *   -81 = full refund
		 *   -82 = partial refund
		 *
		 * @see https://developer.pay.nl/reference/api_get_status-1
		 * @param string $paymentReference The Pay.nl order UUID
		 * @return RefundResult[]
		 * @throws PaymentExchangeException
		 */
		public function getRefunds(string $paymentReference): array {
			// Fetch the order status, which embeds all payment attempts including refunds.
			$result = $this->getGateway()->getOrderStatus($paymentReference);
			
			// A result code of 0 means the API call failed; surface it as an exception.
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// The order-level currency is used as a fallback when a payment entry
			// does not carry its own currency field.
			$data = $result['response'];
			$currency = $data['amount']['currency'] ?? '';
			$refunds = [];
			
			foreach ($data['payments'] ?? [] as $payment) {
				$paymentStatusCode = (int)($payment['status']['code'] ?? 0);
				
				// Skip any payment entry that is not a refund transaction.
				// Regular payment attempts, authorizations, and chargebacks are excluded.
				if ($paymentStatusCode !== -81 && $paymentStatusCode !== -82) {
					continue;
				}
				
				// Each refund entry has its own UUID, amount, and currency.
				$refunds[] = new RefundResult(
					provider: self::DRIVER_NAME,
					paymentReference: $paymentReference,
					refundId: (string)($payment['id'] ?? ''),
					value: (int)($payment['amount']['value'] ?? 0),
					currency: $payment['amount']['currency'] ?? $currency,
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Lazily instantiates and returns the PayNLGateway.
		 * Construction is deferred until first use so that config is guaranteed to be set.
		 * @return PayNLGateway
		 */
		private function getGateway(): PayNLGateway {
			return $this->gateway ??= new PayNLGateway($this);
		}
	}