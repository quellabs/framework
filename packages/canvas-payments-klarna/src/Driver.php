<?php
	
	namespace Quellabs\Payments\Klarna;
	
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
				'test_mode'        => false,
				'api_username'     => '',    // Klarna merchant ID-linked username from dashboard
				'api_password'     => '',    // API password associated with the username
				'return_url'        => '',    // Redirect after successful payment
				'cancel_return_url' => '',    // Redirect after cancellation or failure
				'default_currency' => 'EUR',
				'default_country'  => 'NL',
				'locale'           => 'nl-NL',
				'place_order_mode' => 'CAPTURE_ORDER', // Change to PLACE_ORDER for physical goods
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
			return [];
		}
		
		/**
		 * Initiates a payment by creating a Klarna KP session and an HPP session.
		 *
		 * The flow:
		 *   1. Build the KP session payload with order details and POST to /payments/v1/sessions.
		 *   2. Use the returned kp_session_id to build the HPP session payload.
		 *   3. POST the HPP session to /hpp/v1/sessions.
		 *   4. Return the redirect_url from the HPP session response.
		 *
		 * The hppSessionId is stored in InitiateResult metadata and must be persisted by
		 * the application alongside the order, as it is required for exchange() when
		 * place_order_mode is NONE (where the order_id is not available from the redirect).
		 *
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 * @throws \Exception
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$config = $this->getConfig();
			
			// Validate the payment module
			if (!isset(self::MODULE_TYPE_MAP[$request->paymentModule])) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, "Unknown payment module: '{$request->paymentModule}'");
			}
			
			$currency = $request->currency ?: $config['default_currency'];
			$locale   = $config['locale'];
			
			// Derive purchase country from billing address when available; fall back to config.
			// Klarna uses purchase_country for payment method eligibility and risk assessment.
			$country = $request->billingAddress?->country ?: $config['default_country'];
			
			// --- Step 1: Build the order_lines for the KP session ---
			// Klarna mandates at least one order line. If the request doesn't carry
			// structured line items, synthesise a single catch-all line.
			$orderLines = $this->buildOrderLines($request, $currency);
			
			// --- Step 2: Build the KP (Klarna Payments) session payload ---
			$kpPayload = [
				'intent'            => 'buy',
				'purchase_country'  => $country,
				'purchase_currency' => $currency,
				'locale'            => $locale,
				'order_amount'      => $request->amount,      // In minor units (cents)
				'order_tax_amount'  => 0,                     // Include if you have tax data
				'order_lines'       => $orderLines,
				'acquiring_channel' => 'ECOMMERCE',
			];
			
			// Pre-fill customer data when available to improve Klarna's approval rate
			// and provide a smoother checkout experience via pre-filled fields on the HPP.
			if ($request->billingAddress !== null) {
				$addr = $request->billingAddress;
				
				// Klarna's street_address field expects the full street line including
				// house number (and suffix). PaymentAddress stores these separately,
				// so combine them here.
				$streetLine = trim(implode(' ', array_filter([
					$addr->street,
					$addr->houseNumber,
					$addr->houseNumberSuffix,
				])));
				
				$billingAddress = array_filter([
					'given_name'     => $addr->givenName ?: null,
					'family_name'    => $addr->familyName ?: null,
					'email'          => $addr->email ?: null,
					'phone'          => $addr->phone ?: null,
					'street_address' => $streetLine ?: null,
					'postal_code'    => $addr->postalCode ?: null,
					'city'           => $addr->city ?: null,
					'region'         => $addr->region ?: null,
					'country'        => $addr->country,        // Non-nullable on PaymentAddress
				], fn($v) => $v !== null && $v !== '');
				
				if (!empty($billingAddress)) {
					$kpPayload['billing_address'] = $billingAddress;
				}
			}
			
			// --- Step 3: Create the KP session ---
			$kpResult = $this->getGateway()->createPaymentSession($kpPayload);
			
			if ($kpResult['request']['result'] === 0) {
				throw new PaymentInitiationException(
					self::DRIVER_NAME,
					$kpResult['request']['errorId'],
					'KP session creation failed: ' . $kpResult['request']['errorMessage']
				);
			}
			
			$kpSessionId = $kpResult['response']['session_id'] ?? '';
			
			if (empty($kpSessionId)) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, 'KP session response missing session_id');
			}
			
			// --- Step 4: Build the HPP session payload ---
			// The {{order_id}} placeholder is substituted by Klarna in the success URL
			// at runtime. The application extracts order_id from the query string on return.
			$baseUrl = $config['test_mode'] ? 'https://api.playground.klarna.com' : 'https://api.klarna.com';
			
			$successUrl = $config['return_url'];
			$cancelUrl  = $config['cancel_return_url'];
			
			$hppPayload = [
				'payment_session_url' => $baseUrl . '/payments/v1/sessions/' . $kpSessionId,
				'merchant_urls'       => [
					'success' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?')
						. 'order_id={{order_id}}&authorization_token={{authorization_token}}',
					'failure' => $cancelUrl,
					'cancel'  => $cancelUrl,
					'error'   => $cancelUrl,
				],
				'place_order_mode' => $config['place_order_mode'],
			];
			
			// --- Step 5: Create the HPP session ---
			$hppResult = $this->getGateway()->createHppSession($hppPayload);
			
			if ($hppResult['request']['result'] === 0) {
				throw new PaymentInitiationException(
					self::DRIVER_NAME,
					$hppResult['request']['errorId'],
					'HPP session creation failed: ' . $hppResult['request']['errorMessage']
				);
			}
			
			$hppData   = $hppResult['response'];
			$redirectUrl = $hppData['redirect_url'] ?? '';
			$hppSessionId = $hppData['session_id'] ?? '';
			
			if (empty($redirectUrl)) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, 'HPP session response missing redirect_url');
			}
			
			// Return result. The hppSessionId is critical: if place_order_mode is NONE,
			// the application needs it to poll /hpp/v1/sessions/{id} for the order_id.
			return new InitiateResult(
				provider: self::DRIVER_NAME,
				transactionId: $hppSessionId,   // HPP session ID is our primary reference
				redirectUrl: $redirectUrl,
				metadata: [
					'kpSessionId'   => $kpSessionId,
					'hppSessionId'  => $hppSessionId,
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
		 * @param array  $extraData     Not used for Klarna; kept for interface compatibility
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
			
			$order       = $result['response'];
			$orderStatus = strtoupper($order['status'] ?? '');
			$fraudStatus = strtoupper($order['fraud_status'] ?? '');
			
			// Map Klarna order status to our internal PaymentStatus.
			// Klarna order statuses: AUTHORIZED, PART_CAPTURED, CAPTURED, CANCELLED, EXPIRED.
			// AUTHORIZED means approved but not yet captured (PLACE_ORDER mode).
			// CAPTURED means funds collected (CAPTURE_ORDER mode or after manual capture).
			$state = match ($orderStatus) {
				'CAPTURED', 'PART_CAPTURED' => PaymentStatus::Paid,
				'AUTHORIZED'                => PaymentStatus::Pending,
				'CANCELLED'                 => PaymentStatus::Canceled,
				'EXPIRED'                   => PaymentStatus::Expired,
				default                     => PaymentStatus::Pending,
			};
			
			// Klarna may hold an order for fraud review. Treat REJECTED as failed.
			if ($fraudStatus === 'REJECTED') {
				$state = PaymentStatus::Failed;
			}
			
			$capturedAmount  = (int)($order['captured_amount'] ?? 0);
			$refundedAmount  = (int)($order['refunded_amount'] ?? 0);
			$currency        = $order['purchase_currency'] ?? '';
			$valuePaid       = ($state === PaymentStatus::Paid) ? $capturedAmount : 0;
			
			// order_id is the authoritative reference for refunds. If it is absent from
			// the API response the state is unusable — throw rather than silently corrupt.
			$orderId = $order['order_id'] ?? '';
			
			if (empty($orderId)) {
				throw new PaymentExchangeException(self::DRIVER_NAME, 0, 'Order Management API response missing order_id');
			}
			
			return new PaymentState(
				provider: self::DRIVER_NAME,
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $refundedAmount,
				internalState: $orderStatus,
				metadata: array_filter([
					'paymentReference' => $orderId,
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
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Klarna's refund API requires an explicit refunded_amount.
			// When the caller passes null (meaning "refund everything"), fetch the order
			// to derive the remaining refundable amount: captured_amount - refunded_amount.
			if ($request->amount === null) {
				$orderResult = $this->getGateway()->getOrder($request->paymentReference);
				
				if ($orderResult['request']['result'] === 0) {
					throw new PaymentRefundException(
						self::DRIVER_NAME,
						$orderResult['request']['errorId'],
						'Could not fetch order to determine refund amount: ' . $orderResult['request']['errorMessage']
					);
				}
				
				$captured        = (int)($orderResult['response']['captured_amount'] ?? 0);
				$alreadyRefunded = (int)($orderResult['response']['refunded_amount'] ?? 0);
				$refundAmount    = $captured - $alreadyRefunded;
				
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
			
			$result = $this->getGateway()->refundOrder(
				$request->paymentReference,
				$refundAmount,
				$idempotencyKey,
				$request->description
			);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Klarna's refund response body is empty (201 Created with no body).
			// The refund_id may be available in the Location header, but Symfony HttpClient
			// normalises the response body only. We fall back to a generated reference.
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
		 * Builds a valid order_lines array for the Klarna KP session payload.
		 *
		 * Klarna mandates at least one line item containing name, quantity, unit_price,
		 * total_amount, tax_rate, and total_discount_amount. When the PaymentRequest
		 * does not carry structured line items, a single catch-all line is synthesised.
		 *
		 * All amounts are in minor units (cents). tax_rate is in basis points (2500 = 25%).
		 *
		 * @param PaymentRequest $request  The payment request
		 * @param string         $currency ISO 4217 currency code
		 * @return array<int, array> Valid order_lines array
		 */
		private function buildOrderLines(PaymentRequest $request, string $currency): array {
			// If the request carries structured order lines, use them directly.
			// (Future: PaymentRequest may expose an orderLines property.)
			// For now, always synthesise a single catch-all line.
			return [
				[
					'type'                  => 'physical',
					'reference'             => 'ORDER',
					'name'                  => mb_substr($request->description ?: 'Order', 0, 255),
					'quantity'              => 1,
					'unit_price'            => $request->amount,  // In minor units
					'tax_rate'              => 0,                  // 0 = no tax included
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
			return $this->gateway ??= new KlarnaGateway($this);
		}
	}