<?php
	
	namespace Quellabs\Payments\Stripe;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentInterface;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentInitiationException;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Payments\Contracts\RefundResult;
	
	/**
	 * Stripe Driver
	 * @phpstan-import-type IssuerOption from PaymentInterface
	 */
	class Driver implements PaymentProviderInterface {
		
		use GatewayHelpers;
		
		/**
		 * Driver name
		 */
		const string DRIVER_NAME = "stripe";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
		 */
		private array $config = [];
		
		/**
		 * Maps our internal module names to stripe's gateway type strings.
		 * These are passed as 'type' when creating an order.
		 */
		private const array MODULE_TYPE_MAP = [
			'stripe_multi'      => 'stripe',
			'stripe_card'       => 'card',
			'stripe_ideal'      => 'ideal',
			'stripe_bancontact' => 'bancontact',
		];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var StripeGateway|null
		 */
		private ?StripeGateway $gateway = null;
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array{
		 *     driver: string,
		 *     modules: list<string>
		 * }
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_TYPE_MAP),
			];
		}
		
		/**
		 * Returns the active configuration for this provider instance.
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this provider instance.
		 * Called by the discovery system after instantiation, before any other methods are invoked.
		 * @param array<string, mixed> $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this provider.
		 * Merged with loaded config files during discovery — values from config files take precedence.
		 * @return array{
		 *     test_mode: bool,
		 *     secret_key: string,
		 *     publishable_key: string,
		 *     webhook_secret: string,
		 *     brand_name: string,
		 *     verify_ssl: bool,
		 *     return_url: string,
		 *     cancel_return_url: string
		 * }
		 */
		public function getDefaults(): array {
			return [
				'test_mode'         => false,
				'secret_key'        => '',
				'publishable_key'   => '',
				'webhook_secret'    => '',
				'brand_name'        => '',
				'verify_ssl'        => true,
				'return_url'        => '',
				'cancel_return_url' => '',
			];
		}
		
		/**
		 * Initiate a new payment session by creating a Stripe Checkout Session.
		 * @see https://stripe.com/docs/api/checkout/sessions/create
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			// Resolve the module name to the Stripe payment method type.
			// self::DRIVER_NAME (generic) passes no types — lets Stripe use Dashboard defaults.
			// 'stripe_ideal', 'stripe_card', etc. pin the session to that specific method.
			$moduleType         = self::MODULE_TYPE_MAP[$request->paymentModule] ?? null;
			$paymentMethodTypes = ($moduleType !== null && $moduleType !== self::DRIVER_NAME) ? [$moduleType] : [];
			
			// Call the API for a new checkout session
			$result = $this->getGateway()->createCheckoutSession(
				$request->amount,
				$request->description,
				$request->currency,
				$paymentMethodTypes,
			);
			
			// If tha failed, throw
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Fetch response
			$responseRaw = $result['response'] ?? null;
			
			// Validate response
			if (!is_array($responseRaw) || !isset($responseRaw['id']) || !isset($responseRaw['url'])) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 'MISSING_CHECKOUT_URL', "Invalid gateway response. Missing id and/or redirect url");
			}
			
			// Return response
			return new InitiateResult(
				self::DRIVER_NAME,
				$this->normalizeString($responseRaw['id']),
				$this->normalizeString($responseRaw['url']),
			);
		}
		
		/**
		 * Resolves the current payment state from a Checkout Session ID or PaymentIntent ID.
		 * @see https://stripe.com/docs/api/checkout/sessions/retrieve
		 * @see https://stripe.com/docs/api/payment_intents/retrieve
		 * @param string $transactionId The Checkout Session ID (cs_*) returned by initiate()
		 * @param array<string, mixed> $extraData
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			$action          = $extraData['action'] ?? null;
			$paymentIntentId = isset($extraData['paymentIntentId']) ? ($this->normalizeString($extraData['paymentIntentId']) ?: null) : null;
			
			// Branch 1: Buyer explicitly canceled on the Stripe-hosted checkout page.
			// No API call needed — the absence of any payment attempt is definitive.
			if ($action === 'cancel') {
				return new PaymentState(
					provider: self::DRIVER_NAME,
					transactionId: $transactionId,
					state: PaymentStatus::Canceled,
					currency: '',
					valuePaid: 0,
					valueRefunded: 0,
					internalState: 'cancel',
				);
			}
			
			// Branch 2: Webhook event — requires a PaymentIntent ID, not a session ID.
			// Stripe offers no reverse lookup from intent to session, so we query the intent directly.
			if ($action === 'webhook') {
				// The webhook payload must supply a PaymentIntent ID via extraData; without it
				// there is no way to identify which payment this event refers to.
				if (empty($paymentIntentId)) {
					throw new PaymentExchangeException(self::DRIVER_NAME, 'MISSING_PAYMENT_INTENT_ID', 'Webhook exchange requires a paymentIntentId in extraData.');
				}
				
				// Call the gateway to fetch the payment intent
				$intentResult = $this->getGateway()->getPaymentIntent($paymentIntentId);
				
				// Gateway failure — surface Stripe's error directly rather than swallowing it.
				if ($intentResult['request']['result'] === 0) {
					throw new PaymentExchangeException(self::DRIVER_NAME, $intentResult['request']['errorId'], $intentResult['request']['errorMessage']);
				}
				
				// Use the session ID as transactionId, not the PaymentIntent ID —
				// the session ID is what initiate() returned to the caller and what they have stored.
				$intentRaw = $intentResult['response'] ?? null;
				$intent    = is_array($intentRaw) ? $intentRaw : [];
				$currency  = strtoupper($this->normalizeString($intent['currency'] ?? null, 'EUR')); // Stripe returns lowercase (e.g. 'eur') — normalize to ISO 4217
				return $this->mapPaymentIntentToState($transactionId, $intent, $paymentIntentId, $currency);
			}
			
			// Branch 3: Return URL — transactionId is the session ID.
			// Fetch the session with payment_intent expanded to avoid a second round-trip.
			$sessionResult = $this->getGateway()->getCheckoutSession($transactionId);
			
			// Throw when api call failed
			if ($sessionResult['request']['result'] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $sessionResult['request']['errorId'], $sessionResult['request']['errorMessage']);
			}
			
			// Branch 3a: Session expired before the buyer completed checkout.
			$sessionRaw    = $sessionResult['response'] ?? null;
			$session       = is_array($sessionRaw) ? $sessionRaw : [];
			$currency      = strtoupper($this->normalizeString($session['currency'] ?? null, 'EUR'));
			$sessionStatus = $this->normalizeString($session['status'] ?? null, 'open');
			
			if ($sessionStatus === 'expired') {
				return new PaymentState(
					provider: self::DRIVER_NAME,
					transactionId: $transactionId,
					state: PaymentStatus::Expired,
					currency: $currency,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: 'expired',
				);
			}
			
			// Branch 3b: Session is open but has no attached PaymentIntent yet —
			// buyer has not completed checkout.
			$intent          = is_array($session['payment_intent']) ? $session['payment_intent'] : [];
			$paymentIntentId = $this->normalizeString($intent['id'] ?? null) ?: null;
			
			if (empty($intent)) {
				return new PaymentState(
					provider: self::DRIVER_NAME,
					transactionId: $transactionId,
					state: PaymentStatus::Pending,
					currency: $currency,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: $sessionStatus,
				);
			}
			
			// Branch 3c: Session complete, PaymentIntent present — map intent status to state.
			return $this->mapPaymentIntentToState($transactionId, $intent, $paymentIntentId, $currency);
		}
		
		/**
		 * Issue a refund against a Stripe PaymentIntent.
		 *
		 * Note: $request->paymentReference must be the PaymentIntent ID (pi_*), not the session ID.
		 * This is available in PaymentState::$metadata['paymentReference'] after a successful exchange().
		 *
		 * The description is mapped to the most appropriate Stripe refund reason. Stripe accepts
		 * only three reason values ('duplicate', 'fraudulent', 'requested_by_customer'); all other
		 * descriptions fall back to 'requested_by_customer'.
		 *
		 * @see https://stripe.com/docs/api/refunds/create
		 * @param RefundRequest $request amount=null for full refund, or a minor-unit integer for partial
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Deterministic idempotency key — retrying the same refund request always produces
			// the same key, so a network timeout cannot cause a double-refund.
			$idempotencyKey = hash('sha256', 'refund:' . $request->paymentReference . ':' . ($request->amount ?? 'full'));
			
			// Map description to one of Stripe's accepted refund reason values
			$reason = $this->mapRefundReason($request->description);
			
			// Call the API
			$result = $this->getGateway()->refund(
				$request->paymentReference,
				$request->amount,
				$reason,
				$idempotencyKey,
			);
			
			// If that failed, throw an exception
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Return the result
			$response = $result['response'] ?? [];
			
			return new RefundResult(
				provider: self::DRIVER_NAME,
				paymentReference: $request->paymentReference,
				refundId: $this->normalizeString($this->arrayGet($response, 'id')),
				value: $this->toInt($this->arrayGet($response, 'amount')),
				currency: strtoupper($this->normalizeString($this->arrayGet($response, 'currency'), $request->currency)),
				metadata: [
					'status' => $this->normalizeString($this->arrayGet($response, 'status')) ?: null,
					'reason' => $this->normalizeString($this->arrayGet($response, 'reason')) ?: null,
				],
			);
		}
		
		/**
		 * Stripe does not expose an issuer/bank selection step for any payment method via this
		 * integration — method selection happens on the Stripe-hosted checkout page.
		 * Returns an empty array for all modules.
		 * @param string $paymentModule
		 * @return array<int, IssuerOption>
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Returns all refunds issued for a given PaymentIntent.
		 *
		 * Note: $transactionId must be the PaymentIntent ID (pi_*), not the session ID.
		 * This is available in PaymentState::$metadata['paymentReference'] after a successful exchange().
		 *
		 * @see https://stripe.com/docs/api/refunds/list
		 * @param string $paymentReference The PaymentIntent ID (pi_*)
		 * @return array<RefundResult>
		 * @throws PaymentRefundException
		 */
		public function getRefunds(string $paymentReference): array {
			$result = $this->getGateway()->getRefundsForPaymentIntent($paymentReference);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$refunds     = [];
			$responseRaw = $result['response'] ?? null;
			$responseArr = is_array($responseRaw) ? $responseRaw : [];
			$data        = isset($responseArr['data']) && is_array($responseArr['data']) ? $responseArr['data'] : [];
			
			foreach ($data as $refundRaw) {
				if (!is_array($refundRaw)) {
					continue;
				}
				
				$refunds[] = new RefundResult(
					provider: self::DRIVER_NAME,
					paymentReference: $paymentReference,
					refundId: $this->normalizeString($refundRaw['id'] ?? null),
					value: $this->toInt($refundRaw['amount'] ?? null),
					currency: strtoupper($this->normalizeString($refundRaw['currency'] ?? null)),
					metadata: [
						'status' => isset($refundRaw['status']) ? $this->normalizeString($refundRaw['status']) : null,
						'reason' => isset($refundRaw['reason']) ? $this->normalizeString($refundRaw['reason']) : null,
					],
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Verifies a Stripe webhook notification by delegating HMAC signature validation to the gateway.
		 * @param string $signatureHeader The raw Stripe-Signature header value
		 * @param string $rawBody The raw, unmodified request body string
		 * @return bool
		 */
		public function verifyWebhookSignature(string $signatureHeader, string $rawBody): bool {
			return $this->getGateway()->verifyWebhookSignature($signatureHeader, $rawBody);
		}
		
		/**
		 * Lazily instantiated Stripe gateway.
		 * @return StripeGateway
		 */
		private function getGateway(): StripeGateway {
			return $this->gateway ??= new StripeGateway($this);
		}
		
		/**
		 * Maps a Stripe PaymentIntent object to a normalized PaymentState.
		 * @param string $sessionId Used as transactionId on the returned state
		 * @param array<string, mixed> $intent The PaymentIntent object from the Stripe API
		 * @param string|null $paymentIntentId Stored in metadata so callers can use it for refunds
		 * @param string $currency ISO 4217 currency code (already uppercased)
		 * @return PaymentState
		 */
		private function mapPaymentIntentToState(string $sessionId, array $intent, ?string $paymentIntentId, string $currency): PaymentState {
			$intentStatus   = $this->normalizeString($intent['status'] ?? null, 'unknown');
			$amountReceived = $this->toInt($intent['amount_received'] ?? null);
			$amountRefunded = $this->toInt($intent['amount_refunded'] ?? null);
			
			/** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
			switch ($intentStatus) {
				case 'succeeded':
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $sessionId,
						state: PaymentStatus::Paid,
						currency: $currency,
						valuePaid: $amountReceived,
						valueRefunded: $amountRefunded,
						internalState: 'succeeded',
						metadata: [
							'paymentReference' => $paymentIntentId,
						],
					);
				
				// 3DS or other customer action required — redirect back to Stripe's next_action URL
				case 'requires_action':
					$nextAction  = is_array($intent['next_action'] ?? null) ? $intent['next_action'] : [];
					$redirectUrl = $this->normalizeString($this->arrayGet($nextAction, 'redirect_to_url.url')) ?: null;
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $sessionId,
						state: PaymentStatus::Redirect,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: 'requires_action',
						metadata: [
							'paymentReference' => $paymentIntentId,
							'redirectUrl'      => $redirectUrl,
						],
					);
				
				case 'canceled':
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $sessionId,
						state: PaymentStatus::Canceled,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: 'canceled',
						metadata: [
							'paymentReference'   => $paymentIntentId,
							'cancellationReason' => isset($intent['cancellation_reason']) ? $this->normalizeString($intent['cancellation_reason']) : null,
						],
					);
				
				// requires_payment_method, requires_confirmation, processing, or unknown
				default:
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $sessionId,
						state: PaymentStatus::Pending,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: $intentStatus,
						metadata: [
							'paymentReference' => $paymentIntentId,
						],
					);
			}
		}
		
		/**
		 * Maps a human-readable refund description to one of Stripe's accepted reason values.
		 * Stripe rejects any value outside the allowed set, so unknown descriptions fall back
		 * to 'requested_by_customer'.
		 * @param string $description
		 * @return string
		 */
		private function mapRefundReason(string $description): string {
			$lower = strtolower($description);
			
			// 'dubbel' is Dutch for duplicate — included because Quellabs applications are
			// often Dutch-language and RefundRequest::$description is a free-text field
			if (str_contains($lower, 'duplicate') || str_contains($lower, 'dubbel')) {
				return 'duplicate';
			}
			
			// 'fraude' is Dutch for fraud
			if (str_contains($lower, 'fraud') || str_contains($lower, 'fraude')) {
				return 'fraudulent';
			}
			
			// Stripe rejects any value outside its allowed set of three reasons.
			// 'requested_by_customer' is the safest default for anything that doesn't
			// clearly signal duplicate or fraud.
			return 'requested_by_customer';
		}
	}