<?php
	
	namespace Quellabs\Payments\Klarna;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentAddress;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentInitiationException;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Payments\Contracts\RefundResult;
	use Quellabs\Support\Tools;
	
	/**
	 * Klarna payment driver using the Hosted Payment Page (HPP) integration.
	 *
	 * HPP is the server-side-only integration path — no JavaScript SDK is required,
	 * which makes it compatible with Canvas's server-rendered checkout flows.
	 *
	 * Payment flow:
	 *   1. initiate()  — creates a KP session + HPP session, returns redirect_url
	 *   2. Consumer completes payment on Klarna's hosted page
	 *   3. Klarna redirects to merchant_urls.success with ?order_id=<id>
	 *   4. handleReturn() (in KlarnaController) reads order_id from query string
	 *   5. exchange()   — fetches authoritative order state from Order Management API
	 *
	 * place_order_mode is CAPTURE_ORDER by default. This means Klarna places AND captures
	 * the order automatically when the consumer authorises, and returns order_id directly.
	 * Change to PLACE_ORDER for physical goods requiring manual capture after shipment.
	 *
	 * Klarna requires at least one order_line entry per session. When the PaymentRequest
	 * does not carry line items, a single catch-all line is synthesised from the total
	 * amount. This satisfies the API requirement without breaking the integration.
	 *
	 * @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/
	 */
	class Driver implements PaymentProviderInterface {
		
		/**
		 * Driver name
		 */
		const DRIVER_NAME = "klarna";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var KlarnaGateway|null
		 */
		private ?KlarnaGateway $gateway = null;
		
		/**
		 * Klarna payment method categories available via HPP.
		 *
		 * Klarna automatically presents the appropriate payment options
		 * (Pay now, Pay later, Slice it) based on the consumer's country and
		 * Klarna's real-time risk assessment. Merchants do not pre-select a category.
		 *
		 * These module names act as identifiers for the discovery system; they map to
		 * the single Klarna HPP flow rather than separate API endpoints.
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/web-payments/additional-resources/payment-method-grouping/
		 */
		private const MODULE_TYPE_MAP = [
			'klarna'          => 'klarna',          // All Klarna payment methods (default)
			'klarna_paynow'   => 'pay_now',          // Pay immediately (debit/card)
			'klarna_paylater' => 'pay_later',        // Pay after delivery (invoice)
			'klarna_sliceit'  => 'pay_over_time',    // Installment financing
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
			// Defaults are the base; any key set in $this->config takes precedence.
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
		 *
		 * place_order_mode options:
		 *   CAPTURE_ORDER — Klarna places and captures automatically. Use for digital goods
		 *                   or immediate fulfillment. No manual capture call required.
		 *   PLACE_ORDER   — Klarna places the order; merchant captures after shipping.
		 *   NONE          — Klarna authorises only; merchant places AND captures the order.
		 *
		 * @return array
		 */
		public function getDefaults(): array {
			return [
				'test_mode'         => false,
				'api_username'      => '',    // Klarna merchant ID-linked username from dashboard
				'api_password'      => '',    // API password associated with the username
				'return_url'        => '',    // Redirect after successful payment
				'cancel_return_url' => '',    // Redirect after cancellation or failure
				'default_currency'  => 'EUR',
				'default_country'   => 'NL',
				'locale'            => 'nl-NL',
				'place_order_mode'  => 'CAPTURE_ORDER', // Change to PLACE_ORDER for physical goods
			];
		}
		
		/**
		 * Returns the available payment options (issuers/methods) for a given module.
		 *
		 * Klarna determines which payment categories (Pay now, Pay later, Slice it) are
		 * available per consumer in real time. There is no pre-selectable issuer list;
		 * selection always happens on the Klarna HPP.
		 *
		 * @param string $paymentModule e.g. 'klarna', 'klarna_paylater'
		 * @return array Always empty — Klarna handles payment method UI on the hosted page
		 */
		public function getPaymentOptions(string $paymentModule): array {
			// Klarna has no issuer list — method selection happens on the hosted page.
			return [];
		}
		
		/**
		 * Initiates a payment by creating a KP session and an HPP session, then returning
		 * the redirect URL for the Klarna hosted checkout page.
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			// Reject unknown modules before making any API calls.
			if (!isset(self::MODULE_TYPE_MAP[$request->paymentModule])) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, "Unknown payment module: '{$request->paymentModule}'");
			}
			
			// Create the KP session first
			$kpSessionId = $this->createKpSession($request);
			
			// Use the session ID to initiate a Hosted Payment Page
			$hppData = $this->createHppSession($kpSessionId);
			
			// Extract info from result
			$redirectUrl = $hppData['redirect_url'] ?? '';
			$hppSessionId = $hppData['session_id'] ?? '';
			
			// If redirect url is missing, throw
			if (empty($redirectUrl)) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, 'HPP session response missing redirect_url');
			}
			
			// The HPP session ID is our primary transaction reference for this payment.
			// The KP session ID is stored in metadata for diagnostics.
			return new InitiateResult(
				provider: self::DRIVER_NAME,
				transactionId: $hppSessionId,
				redirectUrl: $redirectUrl,
				metadata: [
					'kpSessionId'  => $kpSessionId,
					'hppSessionId' => $hppSessionId,
				],
			);
		}
		
		/**
		 * Resolves an order_id into a PaymentState by querying the Order Management API.
		 *
		 * The order_id arrives in the success URL query string as ?order_id=<id> when
		 * place_order_mode is PLACE_ORDER or CAPTURE_ORDER. For NONE mode, the
		 * hppSessionId stored in metadata must be read and used to call readHppSession()
		 * first to obtain the order_id.
		 *
		 * For CAPTURE_ORDER mode (the default), the order is already captured when the
		 * consumer lands on the success page. The Order Management API confirms this.
		 *
		 * @param string $transactionId Klarna order_id (UUID from the success redirect)
		 * @param array $extraData Not used for Klarna; kept for interface compatibility
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Fetch the authoritative order state from Klarna's Order Management API.
			// Never trust the query string status alone — always verify server-side.
			$result = $this->getGateway()->getOrder($transactionId);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$order = $result['response'];
			$orderStatus = strtoupper($order['status'] ?? '');
			$fraudStatus = strtoupper($order['fraud_status'] ?? '');
			
			// Map Klarna order status to our internal PaymentStatus.
			// AUTHORIZED means approved but not yet captured (PLACE_ORDER mode).
			// CAPTURED means funds collected (CAPTURE_ORDER mode or after manual capture).
			$state = match ($orderStatus) {
				'CAPTURED', 'PART_CAPTURED' => PaymentStatus::Paid,
				'AUTHORIZED' => PaymentStatus::Pending,
				'CANCELLED' => PaymentStatus::Canceled,
				'EXPIRED' => PaymentStatus::Expired,
				default => PaymentStatus::Pending,
			};
			
			// Klarna may hold an order for fraud review. Treat REJECTED as failed.
			if ($fraudStatus === 'REJECTED') {
				$state = PaymentStatus::Failed;
			}
			
			
			// order_id is the authoritative reference for refunds. If it is absent from
			// the API response the state is unusable — throw rather than silently corrupt.
			$orderId = $order['order_id'] ?? '';
			
			if (empty($orderId)) {
				throw new PaymentExchangeException(self::DRIVER_NAME, 0, 'Order Management API response missing order_id');
			}

			// Return the state
			$capturedAmount = (int)($order['captured_amount'] ?? 0);
			$refundedAmount = (int)($order['refunded_amount'] ?? 0);
			$currency = $order['purchase_currency'] ?? '';
			$valuePaid = ($state === PaymentStatus::Paid) ? $capturedAmount : 0;

			return new PaymentState(
				provider: self::DRIVER_NAME,
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $refundedAmount,
				internalState: $orderStatus,
				metadata: array_filter([
					'paymentReference' => $orderId,       // Use this for refund calls
					'fraudStatus'      => $fraudStatus ?: null,
					'klarnaReference'  => $order['klarna_reference'] ?? null,
				], fn($v) => $v !== null && $v !== ''),
			);
		}
		
		/**
		 * Refunds a previously captured Klarna order.
		 *
		 * Full refund: pass null as $request->amount. The order is fetched from the
		 * Order Management API to derive the remaining refundable amount automatically:
		 * captured_amount - refunded_amount.
		 * Partial refund: pass an explicit amount in minor units as $request->amount.
		 *
		 * An idempotency key is generated per refund call. The application should persist
		 * this key to safely retry on network failure without issuing a duplicate refund.
		 *
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException|\Exception
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Klarna's refund API requires an explicit refunded_amount.
			// When the caller passes null (meaning "refund everything"), fetch the order
			// to derive the remaining refundable amount: captured_amount - refunded_amount.
			if ($request->amount === null) {
				// Fetch order to determine the refundable value
				$orderResult = $this->getGateway()->getOrder($request->paymentReference);
				
				// If that failed, throw
				if ($orderResult['request']['result'] === 0) {
					throw new PaymentRefundException(
						self::DRIVER_NAME,
						$orderResult['request']['errorId'],
						'Could not fetch order to determine refund amount: ' . $orderResult['request']['errorMessage']
					);
				}
				
				// Fetch the captured_amount and refunded_amount to calculate the refundable amount
				$captured = (int)($orderResult['response']['captured_amount'] ?? 0);
				$alreadyRefunded = (int)($orderResult['response']['refunded_amount'] ?? 0);
				$refundAmount = $captured - $alreadyRefunded;
				
				// If no refundable amount remains, throw
				if ($refundAmount <= 0) {
					throw new PaymentRefundException(
						self::DRIVER_NAME,
						0,
						'No refundable amount remaining on this order'
					);
				}
			} else {
				$refundAmount = $request->amount;
			}
			
			// Generate a UUID v4 as the idempotency key for this refund attempt.
			// On retry after a network failure, the same key must be reused.
			$idempotencyKey = Tools::createUUIDv4();
			
			// Call API to issue refund
			$result = $this->getGateway()->refundOrder(
				$request->paymentReference,
				$refundAmount,
				$idempotencyKey,
				$request->description
			);
			
			// If that failed, throw
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Klarna's refund response body is empty (201 Created with no body).
			// The refund_id may be available in the Location header, but Symfony HttpClient
			// normalises the response body only. We fall back to the idempotency key.
			$refundId = (string)($result['response']['refund_id'] ?? $idempotencyKey);
			
			return new RefundResult(
				provider: self::DRIVER_NAME,
				paymentReference: $request->paymentReference,
				refundId: $refundId,
				value: $refundAmount,
				currency: $request->currency,
			);
		}
		
		/**
		 * Returns refund records for a previously completed Klarna order.
		 *
		 * Klarna does not expose a GET endpoint to list all refunds for an order.
		 * Refund details are embedded in the order object returned by getOrder();
		 * the refunded_amount field gives the total refunded so far.
		 *
		 * This method cannot enumerate individual refund transactions without the
		 * application maintaining its own refund ID records (from RefundResult::refundId).
		 *
		 * @param string $paymentReference The Klarna order_id
		 * @return RefundResult[]
		 */
		public function getRefunds(string $paymentReference): array {
			return [];
		}
		
		/**
		 * Creates a Klarna Payments (KP) session and returns its session_id.
		 * @param PaymentRequest $request
		 * @return string KP session_id
		 * @throws PaymentInitiationException
		 */
		private function createKpSession(PaymentRequest $request): string {
			// Fetch config
			$config = $this->getConfig();
			
			// Derive purchase_country from the billing address when available.
			// Klarna uses this to determine which payment methods are eligible.
			$currency = $request->currency ?: $config['default_currency'];
			$country = $request->billingAddress?->country ?: $config['default_country'];
			
			$payload = [
				'intent'            => 'buy',
				'purchase_country'  => $country,
				'purchase_currency' => $currency,
				'locale'            => $config['locale'],
				'order_amount'      => $request->amount,
				'order_tax_amount'  => 0,
				'order_lines'       => $this->buildOrderLines($request, $currency),
				'acquiring_channel' => 'ECOMMERCE',
			];
			
			// Pre-filling the billing address improves Klarna's approval rate and
			// reduces friction on the hosted page by pre-populating customer fields.
			if ($request->billingAddress !== null) {
				$payload['billing_address'] = $this->buildBillingAddress($request->billingAddress);
			}
			
			// Call API to create payment session
			$result = $this->getGateway()->createPaymentSession($payload);
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					'KP session creation failed: ' . $result['request']['errorMessage']
				);
			}
			
			// Fetch the session_id from the result
			$sessionId = $result['response']['session_id'] ?? '';
			
			// If it's not there, throw exception
			if (empty($sessionId)) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, 'KP session response missing session_id');
			}
			
			// Return the session id
			return $sessionId;
		}
		
		/**
		 * Creates a Hosted Payment Page (HPP) session linked to a KP session and returns
		 * the raw HPP response array containing redirect_url and session_id.
		 * @param string $kpSessionId The KP session_id returned by createKpSession()
		 * @return array HPP session response
		 * @throws PaymentInitiationException
		 */
		private function createHppSession(string $kpSessionId): array {
			// Fetch configuration data
			$config = $this->getConfig();
			
			// Build payload
			$baseUrl = $config['test_mode'] ? 'https://api.playground.klarna.com' : 'https://api.klarna.com';
			$successUrl = $config['return_url'];
			$cancelUrl = $config['cancel_return_url'];
			
			$payload = [
				// Link this HPP session to the KP session we just created.
				'payment_session_url' => $baseUrl . '/payments/v1/sessions/' . $kpSessionId,
				'merchant_urls'       => [
					// {{order_id}} is a Klarna placeholder substituted at redirect time.
					'success' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'order_id={{order_id}}',
					'failure' => $cancelUrl,
					'cancel'  => $cancelUrl,
					'error'   => $cancelUrl,
				],
				'place_order_mode'    => $config['place_order_mode'],
			];
			
			// Call gateway to initiate a payment session
			$result = $this->getGateway()->createHppSession($payload);
			
			// If that failed, throw
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					'HPP session creation failed: ' . $result['request']['errorMessage']
				);
			}
			
			// Return the result data
			return $result['response'];
		}
		
		/**
		 * Builds a Klarna-compatible billing address array from a PaymentAddress.
		 * @param PaymentAddress $address
		 * @return array
		 */
		private function buildBillingAddress(PaymentAddress $address): array {
			// Klarna expects a single street_address string; combine the split fields.
			$streetLine = trim(implode(' ', array_filter([
				$address->street,
				$address->houseNumber,
				$address->houseNumberSuffix,
			])));
			
			// Strip null and empty-string values — Klarna rejects unexpected empty fields.
			return array_filter([
				'given_name'     => $address->givenName ?: null,
				'family_name'    => $address->familyName ?: null,
				'email'          => $address->email ?: null,
				'phone'          => $address->phone ?: null,
				'street_address' => $streetLine ?: null,
				'postal_code'    => $address->postalCode ?: null,
				'city'           => $address->city ?: null,
				'region'         => $address->region ?: null,
				'country'        => $address->country,   // Non-nullable on PaymentAddress
			], fn($v) => $v !== null && $v !== '');
		}
		
		/**
		 * Builds a valid order_lines array for the Klarna KP session payload.
		 *
		 * Klarna mandates at least one line item containing name, quantity, unit_price,
		 * total_amount, tax_rate, and total_discount_amount. When the PaymentRequest
		 * does not carry structured line items, a single catch-all line is synthesized.
		 *
		 * All amounts are in minor units (cents). tax_rate is in basis points (2500 = 25%).
		 *
		 * @param PaymentRequest $request The payment request
		 * @param string $currency ISO 4217 currency code
		 * @return array<int, array> Valid order_lines array
		 */
		private function buildOrderLines(PaymentRequest $request, string $currency): array {
			// Synthesize a single catch-all line from the total amount.
			// When PaymentRequest gains a structured orderLines property, pass those through here instead.
			return [
				[
					'type'                  => 'physical',
					'reference'             => 'ORDER',
					'name'                  => mb_substr($request->description ?: 'Order', 0, 255),
					'quantity'              => 1,
					'unit_price'            => $request->amount,   // In minor units (cents)
					'tax_rate'              => 0,                  // 0 = no tax breakdown provided
					'total_amount'          => $request->amount,
					'total_discount_amount' => 0,
					'total_tax_amount'      => 0,
				],
			];
		}
		
		/**
		 * Lazily instantiates and returns the KlarnaGateway.
		 * Construction is deferred until first use so that config is guaranteed to be set.
		 * @return KlarnaGateway
		 */
		private function getGateway(): KlarnaGateway {
			// Instantiate on first use; reuse the same instance for subsequent calls.
			return $this->gateway ??= new KlarnaGateway($this);
		}
	}