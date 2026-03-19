<?php
	
	namespace Quellabs\Payments\Stripe;
	
	use Symfony\Component\HttpClient\HttpClient;
	
	/**
	 * Low-level wrapper around the Stripe Payment Intents and Checkout Sessions APIs.
	 * Handles HTTP Basic authentication, raw HTTP communication, and response normalization.
	 *
	 * Authentication: Stripe uses the secret key as the HTTP Basic auth username with an empty
	 * password — no OAuth2 token exchange is required.
	 *
	 * All monetary values passed to and returned from Stripe are in the smallest currency unit
	 * (e.g. cents). No conversion is performed here — callers own that responsibility.
	 *
	 * @see https://stripe.com/docs/api
	 * @see https://stripe.com/docs/payments/payment-intents
	 * @see https://stripe.com/docs/payments/checkout
	 */
	class StripeGateway {
		
		private const BASE_URL      = 'https://api.stripe.com';
		private const API_VERSION   = '2024-06-20';
		
		private string  $m_secret_key;
		private string  $m_webhook_secret;
		private bool    $m_verify_ssl;
		private bool    $m_test_mode;
		private string  $m_return_url;
		private string  $m_cancel_url;
		private \Symfony\Contracts\HttpClient\HttpClientInterface $m_client;
		
		/**
		 * StripeGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			$this->m_test_mode      = $config['test_mode'];
			$this->m_secret_key     = $config['secret_key'];
			$this->m_webhook_secret = $config['webhook_secret'];
			$this->m_verify_ssl     = $config['verify_ssl'];
			$this->m_return_url     = $config['return_url'] ?? '';
			$this->m_cancel_url     = $config['cancel_return_url'] ?? '';
			$this->m_client         = HttpClient::create();
		}
		
		/**
		 * Returns true when operating against Stripe's test environment.
		 * A test secret key (sk_test_*) always implies test mode regardless of config.
		 * @return bool
		 */
		public function testMode(): bool {
			return $this->m_test_mode;
		}
		
		/**
		 * Creates a Stripe Checkout Session in payment mode.
		 *
		 * Checkout Sessions handle the redirect flow and auto-capture for card payments.
		 * The session ID is returned as the transactionId so it can be used as the lookup
		 * key when the buyer returns. The underlying PaymentIntent ID is also included in
		 * the response for webhook correlation.
		 *
		 * @see https://stripe.com/docs/api/checkout/sessions/create
		 * @param int    $amount      Amount in the smallest currency unit (e.g. cents)
		 * @param string $description Line item description shown on the Stripe-hosted checkout page
		 * @param string $currency    ISO 4217 currency code (e.g. 'eur', 'usd')
		 * @param string $brandName   Optional display name shown on the Stripe checkout page
		 * @return array Normalized result envelope
		 */
		public function createCheckoutSession(int $amount, string $description, string $currency, string $brandName = ''): array {
			// Stripe appends the session ID to return_url automatically when {CHECKOUT_SESSION_ID}
			// is present in the URL. This is how we correlate the return visit to the session.
			$returnUrl = $this->appendSessionPlaceholder($this->m_return_url);
			$cancelUrl = $this->m_cancel_url;
			
			$body = [
				'mode'                   => 'payment',
				'success_url'            => $returnUrl,
				'cancel_url'             => $cancelUrl,
				'currency'               => strtolower($currency),
				'line_items[0][quantity]'                    => 1,
				'line_items[0][price_data][currency]'        => strtolower($currency),
				'line_items[0][price_data][unit_amount]'     => $amount,
				'line_items[0][price_data][product_data][name]' => $description,
				'payment_intent_data[description]'           => $description,
			];
			
			if (!empty($brandName)) {
				// Checkout Sessions do not have a direct brand_name field — the branding is
				// controlled in the Stripe Dashboard under Branding settings. The closest
				// per-session equivalent is setting the statement_descriptor.
				$body['payment_intent_data[statement_descriptor]'] = substr($brandName, 0, 22);
			}
			
			return $this->sendRequest('POST', '/v1/checkout/sessions', $body);
		}
		
		/**
		 * Retrieves a Checkout Session by its ID.
		 * Used on the return URL to determine the session outcome and resolve the PaymentIntent.
		 * @see https://stripe.com/docs/api/checkout/sessions/retrieve
		 * @param string $sessionId The Checkout Session ID (cs_*)
		 * @return array Normalized result envelope
		 */
		public function getCheckoutSession(string $sessionId): array {
			if (empty($sessionId)) {
				return $this->errorEnvelope('MISSING_SESSION_ID', 'Missing Checkout Session ID');
			}
			
			// Expand the payment_intent object so we get status, amount, and currency inline
			// without a second API call.
			return $this->sendRequest('GET', '/v1/checkout/sessions/' . urlencode($sessionId), [
				'expand[]' => 'payment_intent',
			]);
		}
		
		/**
		 * Retrieves a PaymentIntent by its ID.
		 * Used on webhook events that carry only the PaymentIntent ID (e.g. payment_intent.succeeded).
		 * @see https://stripe.com/docs/api/payment_intents/retrieve
		 * @param string $paymentIntentId The PaymentIntent ID (pi_*)
		 * @return array Normalized result envelope
		 */
		public function getPaymentIntent(string $paymentIntentId): array {
			if (empty($paymentIntentId)) {
				return $this->errorEnvelope('MISSING_PAYMENT_INTENT_ID', 'Missing PaymentIntent ID');
			}
			
			return $this->sendRequest('GET', '/v1/payment_intents/' . urlencode($paymentIntentId));
		}
		
		/**
		 * Issues a full or partial refund against a PaymentIntent.
		 * Stripe resolves the correct charge to refund automatically from the PaymentIntent ID.
		 * @see https://stripe.com/docs/api/refunds/create
		 * @param string      $paymentIntentId The PaymentIntent ID (pi_*)
		 * @param int|null    $amount          Amount in smallest currency unit, or null for a full refund
		 * @param string      $reason          Stripe refund reason: 'duplicate', 'fraudulent', or 'requested_by_customer'
		 * @param string      $idempotencyKey  Unique key to make this request safely retryable
		 * @return array Normalized result envelope
		 */
		public function refund(string $paymentIntentId, ?int $amount, string $reason, string $idempotencyKey): array {
			$body = [
				'payment_intent' => $paymentIntentId,
				'reason'         => $reason,
			];
			
			// Omitting amount triggers a full refund on Stripe's side
			if ($amount !== null) {
				$body['amount'] = $amount;
			}
			
			return $this->sendRequest('POST', '/v1/refunds', $body, [
				'Idempotency-Key' => $idempotencyKey,
			]);
		}
		
		/**
		 * Returns all refunds issued for a given PaymentIntent.
		 * @see https://stripe.com/docs/api/refunds/list
		 * @param string $paymentIntentId The PaymentIntent ID (pi_*)
		 * @return array Normalized result envelope; on success 'response' is an array of refund objects
		 */
		public function getRefundsForPaymentIntent(string $paymentIntentId): array {
			return $this->sendRequest('GET', '/v1/refunds', [
				'payment_intent' => $paymentIntentId,
				'limit'          => 100,
			]);
		}
		
		/**
		 * Verifies a Stripe webhook notification by validating the Stripe-Signature header.
		 *
		 * Stripe computes an HMAC-SHA256 signature over "{timestamp}.{rawBody}" using the
		 * webhook endpoint's signing secret. We replicate this locally — no outbound API call
		 * is needed, unlike PayPal's verify-webhook-signature endpoint.
		 *
		 * Replay protection: we reject events older than 5 minutes (300 seconds).
		 *
		 * @see https://stripe.com/docs/webhooks/signatures
		 * @param string $signatureHeader The raw value of the Stripe-Signature header
		 * @param string $rawBody         The raw, unmodified request body
		 * @return bool True if the webhook is genuine and recent, false otherwise
		 */
		public function verifyWebhookSignature(string $signatureHeader, string $rawBody): bool {
			if (empty($signatureHeader) || empty($this->m_webhook_secret)) {
				return false;
			}
			
			// Parse the header: "t=1492774577,v1=5257a869...,v0=..."
			$params = [];
			
			foreach (explode(',', $signatureHeader) as $part) {
				[$key, $value] = explode('=', $part, 2) + ['', ''];
				$params[$key][] = $value;
			}
			
			$timestamp = (int)($params['t'][0] ?? 0);
			
			if ($timestamp === 0) {
				return false;
			}
			
			// Reject events older than 5 minutes to mitigate replay attacks
			if (abs(time() - $timestamp) > 300) {
				return false;
			}
			
			$signatures = $params['v1'] ?? [];
			
			if (empty($signatures)) {
				return false;
			}
			
			// Compute the expected signature
			$signedPayload = $timestamp . '.' . $rawBody;
			$expected      = hash_hmac('sha256', $signedPayload, $this->m_webhook_secret);
			
			// Use hash_equals to prevent timing attacks
			foreach ($signatures as $sig) {
				if (hash_equals($expected, $sig)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Appends Stripe's {CHECKOUT_SESSION_ID} placeholder to the return URL.
		 * Stripe replaces this with the real session ID when redirecting the buyer back,
		 * allowing us to retrieve the session on return without server-side storage.
		 * @param string $url
		 * @return string
		 */
		private function appendSessionPlaceholder(string $url): string {
			if (empty($url)) {
				return $url;
			}
			
			$separator = str_contains($url, '?') ? '&' : '?';
			return $url . $separator . 'session_id={CHECKOUT_SESSION_ID}';
		}
		
		/**
		 * Builds a normalized error envelope without making an API call.
		 * Used for early validation failures before a request is sent.
		 * @param string $errorId
		 * @param string $errorMessage
		 * @return array
		 */
		private function errorEnvelope(string $errorId, string $errorMessage): array {
			return ['request' => ['result' => 0, 'errorId' => $errorId, 'errorMessage' => $errorMessage], 'response' => []];
		}
		
		/**
		 * Send an authenticated REST request to the Stripe API and return a normalized response array.
		 * All API methods funnel through here to keep HTTP handling in one place.
		 *
		 * Stripe uses HTTP Basic auth with the secret key as the username and an empty password.
		 * Request bodies for POST requests use application/x-www-form-urlencoded (not JSON).
		 * GET parameters are passed as query string entries.
		 *
		 * @param string $method  HTTP method: GET, POST
		 * @param string $path    API path, e.g. /v1/checkout/sessions
		 * @param array  $body    Request body for POST, or query params for GET
		 * @param array  $headers Extra headers to merge in (e.g. Idempotency-Key)
		 * @return array ['request' => ['result' => 1|0, 'errorId' => ..., 'errorMessage' => ...], 'response' => [...]]
		 */
		private function sendRequest(string $method, string $path, array $body = [], array $headers = []): array {
			try {
				$options = [
					'auth_basic'  => [$this->m_secret_key, ''],
					'headers'     => array_merge([
						'Stripe-Version' => self::API_VERSION,
					], $headers),
					'verify_peer' => $this->m_verify_ssl,
				];
				
				if ($method === 'GET') {
					// Stripe accepts GET parameters as query string entries
					if (!empty($body)) {
						$options['query'] = $body;
					}
				} else {
					// Stripe's REST API uses form-encoded bodies, not JSON
					$options['body'] = !empty($body) ? http_build_query($body) : '';
					$options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
				}
				
				$response = $this->m_client->request($method, self::BASE_URL . $path, $options);
				$data     = $response->toArray(false);
				$status   = $response->getStatusCode();
				
				// 2xx = success
				if ($status >= 200 && $status < 300) {
					return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $data];
				}
				
				// Stripe error body: {"error": {"type": "...", "code": "...", "message": "..."}}
				$error        = $data['error'] ?? [];
				$errorId      = $error['code'] ?? $error['type'] ?? 'UNKNOWN_ERROR';
				$errorMessage = $error['message'] ?? 'Unknown Stripe error';
				
				return ['request' => ['result' => 0, 'errorId' => $errorId, 'errorMessage' => $errorMessage], 'response' => $data];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => (string)$e->getCode(), 'errorMessage' => $e->getMessage()], 'response' => []];
			}
		}
	}