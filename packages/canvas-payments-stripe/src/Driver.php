<?php
	
	namespace Quellabs\Payments\Stripe;
	
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
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config;
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var StripeGateway|null
		 */
		private ?StripeGateway $gateway = null;
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'modules' => [
					'stripe',
					'card',        // Generic card payments via Stripe
					'ideal',       // iDEAL (Netherlands)
					'bancontact',  // Bancontact (Belgium)
					'sepa_debit',  // SEPA Direct Debit
				]
			];
		}
		
		/**
		 * Returns the active configuration for this provider instance.
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
		 *
		 * Returns a redirect URL pointing to the Stripe-hosted checkout page.
		 * The Checkout Session ID (cs_*) serves as the transactionId throughout the lifecycle —
		 * it is appended by Stripe to the return URL as ?session_id={cs_...} so we can
		 * retrieve the session on return without server-side storage.
		 *
		 * @see https://stripe.com/docs/api/checkout/sessions/create
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$config = $this->getConfig();
			
			$result = $this->getGateway()->createCheckoutSession(
				$request->amount,
				$request->description,
				$request->currency,
				$config['brand_name'] ?? '',
			);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException('stripe', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$sessionId  = $result['response']['id'];
			$checkoutUrl = $result['response']['url'];
			
			if (empty($checkoutUrl)) {
				throw new PaymentInitiationException('stripe', 'MISSING_CHECKOUT_URL', 'Stripe response did not include a checkout URL.');
			}
			
			return new InitiateResult(
				'stripe',
				$sessionId,
				$checkoutUrl,
			);
		}
		
		/**
		 * Resolves the current payment state from a Checkout Session ID or PaymentIntent ID.
		 *
		 * Called when:
		 *   - The buyer returns from Stripe (action = 'return' | 'cancel')
		 *   - A webhook arrives (action = 'webhook')
		 *
		 * extraData keys:
		 *   'action'          — 'cancel' | 'return' | 'webhook'
		 *   'paymentIntentId' — required for webhook events that carry a PaymentIntent ID
		 *                       rather than a session ID. When present, the driver queries the
		 *                       PaymentIntent directly and skips the session lookup.
		 *   'eventType'       — the raw Stripe event type (e.g. 'payment_intent.succeeded')
		 *
		 * @see https://stripe.com/docs/api/checkout/sessions/retrieve
		 * @see https://stripe.com/docs/api/payment_intents/retrieve
		 * @param string $transactionId The Checkout Session ID (cs_*) returned by initiate()
		 * @param array  $extraData
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			$action = $extraData['action'] ?? null;
			
			// Buyer clicked cancel on the Stripe checkout page — no payment was attempted.
			// Return a canceled state immediately without querying the API.
			if ($action === 'cancel') {
				return new PaymentState(
					provider: 'stripe',
					transactionId: $transactionId,
					state: PaymentStatus::Canceled,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: 'cancel',
					currency: '',
				);
			}
			
			// Webhook events carry a PaymentIntent ID, not a session ID. Stripe does not expose
			// a way to look up the session from the PaymentIntent, so we query the intent directly.
			$paymentIntentId = $extraData['paymentIntentId'] ?? null;
			
			if ($action === 'webhook' && !empty($paymentIntentId)) {
				return $this->buildStateFromPaymentIntent($transactionId, $paymentIntentId);
			}
			
			// Return URL: transactionId is the session ID. Fetch the session with the PaymentIntent
			// expanded inline to avoid a second round-trip.
			$sessionResult = $this->getGateway()->getCheckoutSession($transactionId);
			
			if ($sessionResult['request']['result'] === 0) {
				throw new PaymentExchangeException('stripe', $sessionResult['request']['errorId'], $sessionResult['request']['errorMessage']);
			}
			
			$session  = $sessionResult['response'];
			$currency = strtoupper($session['currency'] ?? 'EUR');
			
			// Stripe Checkout Session statuses:
			//   open      — the buyer has not yet completed checkout
			//   complete  — checkout was completed; check payment_intent.status for capture outcome
			//   expired   — the session expired without the buyer completing checkout
			$sessionStatus = $session['status'] ?? 'open';
			
			if ($sessionStatus === 'expired') {
				return new PaymentState(
					provider: 'stripe',
					transactionId: $transactionId,
					state: PaymentStatus::Expired,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: 'expired',
					currency: $currency,
				);
			}
			
			// The payment_intent is expanded on the session response — extract it directly
			$intent = is_array($session['payment_intent']) ? $session['payment_intent'] : [];
			
			if (empty($intent)) {
				// Session is still open (buyer hasn't completed yet) or intent not expanded
				return new PaymentState(
					provider: 'stripe',
					transactionId: $transactionId,
					state: PaymentStatus::Pending,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: $sessionStatus,
					currency: $currency,
				);
			}
			
			$paymentIntentId = $intent['id'] ?? null;
			return $this->mapPaymentIntentToState($transactionId, $intent, $paymentIntentId, $currency);
		}
		
		/**
		 * Issue a refund against a Stripe PaymentIntent.
		 *
		 * Note: $request->transactionId must be the PaymentIntent ID (pi_*), not the session ID.
		 * This is available in PaymentState::$metadata['paymentIntentId'] after a successful exchange().
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
			$idempotencyKey = hash('sha256', 'refund:' . $request->transactionId . ':' . ($request->amount ?? 'full'));
			
			// Map description to one of Stripe's accepted refund reason values
			$reason = $this->mapRefundReason($request->description);
			
			$result = $this->getGateway()->refund(
				$request->transactionId,
				$request->amount,
				$reason,
				$idempotencyKey,
			);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException('stripe', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$r = $result['response'];
			
			return new RefundResult(
				provider: 'stripe',
				transactionId: $request->transactionId,
				refundId: $r['id'],
				value: (int)($r['amount'] ?? 0),
				currency: strtoupper($r['currency'] ?? $request->currency),
				metadata: [
					'status' => $r['status'] ?? null,
					'reason' => $r['reason'] ?? null,
				],
			);
		}
		
		/**
		 * Stripe does not expose an issuer/bank selection step for any payment method via this
		 * integration — method selection happens on the Stripe-hosted checkout page.
		 * Returns an empty array for all modules.
		 * @param string $paymentModule
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Returns all refunds issued for a given PaymentIntent.
		 *
		 * Note: $transactionId must be the PaymentIntent ID (pi_*), not the session ID.
		 * This is available in PaymentState::$metadata['paymentIntentId'] after a successful exchange().
		 *
		 * @see https://stripe.com/docs/api/refunds/list
		 * @param string $transactionId The PaymentIntent ID (pi_*)
		 * @return array<RefundResult>
		 * @throws PaymentRefundException
		 */
		public function getRefunds(string $transactionId): array {
			$result = $this->getGateway()->getRefundsForPaymentIntent($transactionId);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException('stripe', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$refunds = [];
			
			foreach ($result['response']['data'] ?? [] as $refund) {
				$refunds[] = new RefundResult(
					provider: 'stripe',
					transactionId: $transactionId,
					refundId: $refund['id'],
					value: (int)($refund['amount'] ?? 0),
					currency: strtoupper($refund['currency'] ?? ''),
					metadata: [
						'status' => $refund['status'] ?? null,
						'reason' => $refund['reason'] ?? null,
					],
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Verifies a Stripe webhook notification by delegating HMAC signature validation to the gateway.
		 * @param string $signatureHeader The raw Stripe-Signature header value
		 * @param string $rawBody         The raw, unmodified request body string
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
		 * Fetches a PaymentIntent and maps it to a PaymentState.
		 * Used for webhook actions where the event carries a PaymentIntent ID rather than a session ID.
		 * @param string $sessionId       The Checkout Session ID (cs_*), used as transactionId
		 * @param string $paymentIntentId The PaymentIntent ID (pi_*)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		private function buildStateFromPaymentIntent(string $sessionId, string $paymentIntentId): PaymentState {
			$result = $this->getGateway()->getPaymentIntent($paymentIntentId);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException('stripe', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$intent   = $result['response'];
			$currency = strtoupper($intent['currency'] ?? 'EUR');
			
			return $this->mapPaymentIntentToState($sessionId, $intent, $paymentIntentId, $currency);
		}
		
		/**
		 * Maps a Stripe PaymentIntent object to a normalized PaymentState.
		 *
		 * PaymentIntent statuses and their meanings:
		 *   requires_payment_method — no card yet (buyer never completed); treat as Pending
		 *   requires_confirmation   — intent created but not confirmed; treat as Pending
		 *   requires_action         — SCA/3DS required; redirect the buyer back to Stripe
		 *   processing              — funds being captured; treat as Pending (not yet settled)
		 *   succeeded               — payment captured successfully; treat as Paid
		 *   canceled                — intent was canceled (no funds moved); treat as Canceled
		 *
		 * @param string      $sessionId       Used as transactionId on the returned state
		 * @param array       $intent          The PaymentIntent object from the Stripe API
		 * @param string|null $paymentIntentId Stored in metadata so callers can use it for refunds
		 * @param string      $currency        ISO 4217 currency code (already uppercased)
		 * @return PaymentState
		 */
		private function mapPaymentIntentToState(string $sessionId, array $intent, ?string $paymentIntentId, string $currency): PaymentState {
			$intentStatus = $intent['status'] ?? 'unknown';
			$amountReceived = (int)($intent['amount_received'] ?? 0);
			$amountRefunded = (int)($intent['amount_refunded'] ?? 0);
			
			return match ($intentStatus) {
				'succeeded' => new PaymentState(
					provider: 'stripe',
					transactionId: $sessionId,
					state: PaymentStatus::Paid,
					valuePaid: $amountReceived,
					valueRefunded: $amountRefunded,
					internalState: 'succeeded',
					currency: $currency,
					metadata: [
						'paymentIntentId' => $paymentIntentId,
					],
				),
				
				// 3DS or other customer action required — redirect back to Stripe's next_action URL
				'requires_action' => new PaymentState(
					provider: 'stripe',
					transactionId: $sessionId,
					state: PaymentStatus::Redirect,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: 'requires_action',
					currency: $currency,
					metadata: [
						'paymentIntentId' => $paymentIntentId,
						'redirectUrl'     => $intent['next_action']['redirect_to_url']['url'] ?? null,
					],
				),
				
				'canceled' => new PaymentState(
					provider: 'stripe',
					transactionId: $sessionId,
					state: PaymentStatus::Canceled,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: 'canceled',
					currency: $currency,
					metadata: [
						'paymentIntentId'   => $paymentIntentId,
						'cancellationReason' => $intent['cancellation_reason'] ?? null,
					],
				),
				
				// requires_payment_method, requires_confirmation, processing, or unknown
				default => new PaymentState(
					provider: 'stripe',
					transactionId: $sessionId,
					state: PaymentStatus::Pending,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: $intentStatus,
					currency: $currency,
					metadata: [
						'paymentIntentId' => $paymentIntentId,
					],
				),
			};
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
			
			if (str_contains($lower, 'duplicate') || str_contains($lower, 'dubbel')) {
				return 'duplicate';
			}
			
			if (str_contains($lower, 'fraud') || str_contains($lower, 'fraude')) {
				return 'fraudulent';
			}
			
			return 'requested_by_customer';
		}
	}