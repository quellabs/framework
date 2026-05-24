<?php
	
	namespace Quellabs\Payments\Paypal;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the PayPal Orders v2 and Payments v2 REST APIs.
	 * Handles OAuth2 token management, raw HTTP communication, and response normalization.
	 * @see https://developer.paypal.com/docs/api/orders/v2/
	 * @see https://developer.paypal.com/docs/api/payments/v2/
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class PaypalGateway {
		
		use GatewayHelpers;
		
		private string $m_base_url;
		private string $m_client_id;
		private string $m_client_secret;
		private bool $m_verify_ssl;
		private bool $m_account_optional;
		private bool $m_test_mode;
		private string $m_return_url;
		private string $m_cancel_url;
		private string $m_webhook_id;
		private ?string $m_access_token = null;
		private int $m_token_expires = 0;
		private HttpClientInterface $m_client;
		
		/**
		 * PaypalGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			// The Driver::getDefaults() return type guarantees these keys exist with the correct
			// types, but getConfig() merges via array_replace_recursive() which widens to
			// array<string, mixed>. We validate each value explicitly so PHPStan is satisfied
			// and we surface bad config early rather than at call time.
			$this->m_test_mode        = $this->toBool($config["test_mode"] ?? false);
			$this->m_base_url         = $this->m_test_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
			$this->m_client_id        = $this->normalizeString($config["client_id"] ?? '');
			$this->m_client_secret    = $this->normalizeString($config["client_secret"] ?? '');
			$this->m_verify_ssl       = $this->toBool($config["verify_ssl"] ?? true);
			$this->m_account_optional = $this->toBool($config["account_optional"] ?? true);
			$this->m_return_url       = $this->normalizeString($config["return_url"] ?? '');
			$this->m_cancel_url       = $this->normalizeString($config["cancel_return_url"] ?? '');
			$this->m_webhook_id       = $this->normalizeString($config["webhook_id"] ?? '');
			$this->m_client           = HttpClient::create();
		}
		
		/**
		 * Return test mode true/false
		 * @return bool
		 */
		public function testMode(): bool {
			return $this->m_test_mode;
		}
		
		/**
		 * Creates a new PayPal order and returns its ID, which serves as the checkout token.
		 * Equivalent to NVP SetExpressCheckout.
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_create
		 * @param float $value Payment amount in major units (e.g. 12.50)
		 * @param string $description Order description shown on the PayPal checkout page
		 * @param string $currency ISO 4217 currency code (default: EUR)
		 * @param string $brandName Optional brand name shown on the PayPal checkout page
		 * @return GatewayResponse
		 */
		public function createOrder(float $value, string $description, string $currency = "EUR", string $brandName = ""): array {
			$experienceContext = array_filter([
				'payment_method_preference' => $this->m_account_optional ? 'UNRESTRICTED' : 'IMMEDIATE_PAYMENT_REQUIRED',
				'user_action'               => 'PAY_NOW',
				'return_url'                => $this->m_return_url,
				'cancel_url'                => $this->m_cancel_url,
				'brand_name'                => $brandName ?: null,
			]);
			
			$body = [
				'intent'         => 'CAPTURE',
				'payment_source' => [
					'paypal' => [
						'experience_context' => $experienceContext,
					],
				],
				'purchase_units' => [[
					'amount'      => [
						'currency_code' => $currency,
						'value'         => number_format($value, 2, '.', ''),
					],
					'description' => $description,
				]],
			];
			
			return $this->sendRequest('POST', '/v2/checkout/orders', $body);
		}
		
		/**
		 * Retrieves the current state of an order.
		 * Equivalent to NVP GetExpressCheckoutDetails.
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_get
		 * @param string $orderId The order ID returned by createOrder
		 * @return GatewayResponse
		 */
		public function getOrder(string $orderId): array {
			if (empty($orderId)) {
				return ['request' => ['result' => 0, 'errorId' => 'MISSING_ORDER_ID', 'errorMessage' => 'Missing order ID']];
			}
			
			return $this->sendRequest('GET', '/v2/checkout/orders/' . urlencode($orderId));
		}
		
		/**
		 * Captures payment for an approved order.
		 * Equivalent to NVP DoExpressCheckoutPayment.
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		 * @param string $orderId The order ID returned by createOrder
		 * @param string $idempotencyKey Unique key to make this request safely retryable
		 * @return GatewayResponse
		 */
		public function captureOrder(string $orderId, string $idempotencyKey): array {
			// Content-Type must be application/json but the body can be empty for a simple capture.
			// PayPal still requires the header to be present, so we pass an empty body.
			return $this->sendRequest('POST', '/v2/checkout/orders/' . urlencode($orderId) . '/capture', [], [
				'PayPal-Request-Id' => $idempotencyKey,
			]);
		}
		
		/**
		 * Retrieves details for a capture, including refunded amounts.
		 * Equivalent to NVP GetTransactionDetails.
		 * @see https://developer.paypal.com/docs/api/payments/v2/#captures_get
		 * @param string $captureId The capture ID from captureOrder (purchase_units[0].payments.captures[0].id)
		 * @return GatewayResponse
		 */
		public function getCapture(string $captureId): array {
			return $this->sendRequest('GET', '/v2/payments/captures/' . urlencode($captureId));
		}
		
		/**
		 * Refunds a captured payment, either fully or partially.
		 * Equivalent to NVP RefundTransaction.
		 * @see https://developer.paypal.com/docs/api/payments/v2/#captures_refund
		 * @param string $captureId The capture ID to refund
		 * @param float|null $value Refund amount in major units, or null for a full refund
		 * @param string|null $currencyType ISO 4217 currency code, required when $value is set
		 * @param string $note Human-readable reason for the refund, shown to the buyer
		 * @param string $idempotencyKey Unique key to make this request safely retryable
		 * @return GatewayResponse
		 */
		public function refund(string $captureId, ?float $value, ?string $currencyType, string $note, string $idempotencyKey): array {
			// Add payment note
			$body = [
				'note_to_payer' => substr($note, 0, 255)
			];
			
			// Omitting the amount field triggers a full refund on PayPal's side
			if ($value !== null && $currencyType !== null) {
				$body['amount'] = [
					'value'         => number_format($value, 2, '.', ''),
					'currency_code' => $currencyType,
				];
			}
			
			// Call the gateway
			return $this->sendRequest('POST', '/v2/payments/captures/' . urlencode($captureId) . '/refund', $body, [
				'PayPal-Request-Id' => $idempotencyKey,
			]);
		}
		
		/**
		 * Returns all refunds issued for a given capture.
		 * @see https://developer.paypal.com/docs/api/payments/v2/#captures_get
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_get
		 * @see https://developer.paypal.com/docs/api/payments/v2/#refunds_get
		 * @param string $captureId The capture ID
		 * @return GatewayResponse
		 */
		public function getRefundsForCapture(string $captureId): array {
			// Step 1: Fetch the capture to get the order ID.
			// The capture endpoint (GET /v2/payments/captures/{id}) does NOT embed refund stubs —
			// that structure lives on the order. The order ID is available via supplementary_data.
			$capture = $this->getCapture($captureId);
			
			if ($capture['request']['result'] === 0) {
				return $capture;
			}
			
			// Step 2: Resolve the order ID from the capture's supplementary_data.
			$captureResponse = isset($capture['response']) && is_array($capture['response']) ? $capture['response'] : [];
			$suppData        = isset($captureResponse['supplementary_data']) && is_array($captureResponse['supplementary_data']) ? $captureResponse['supplementary_data'] : [];
			$relatedIds      = isset($suppData['related_ids']) && is_array($suppData['related_ids']) ? $suppData['related_ids'] : [];
			$orderId         = isset($relatedIds['order_id']) && is_string($relatedIds['order_id']) ? $relatedIds['order_id'] : null;
			
			if ($orderId === null) {
				return ['request' => ['result' => 0, 'errorId' => 'MISSING_ORDER_ID', 'errorMessage' => 'Could not resolve order ID from capture supplementary_data'], 'response' => []];
			}
			
			// Step 3: Fetch the order — refund stubs are under purchase_units[0].payments.refunds
			$order = $this->sendRequest('GET', '/v2/checkout/orders/' . urlencode($orderId));
			
			if ($order['request']['result'] === 0) {
				return $order;
			}
			
			// Step 4: Extract the list of refund stubs from the order response
			$orderResponse = isset($order['response']) && is_array($order['response']) ? $order['response'] : [];
			$purchaseUnits = isset($orderResponse['purchase_units']) && is_array($orderResponse['purchase_units']) ? $orderResponse['purchase_units'] : [];
			$firstUnit     = isset($purchaseUnits[0]) && is_array($purchaseUnits[0]) ? $purchaseUnits[0] : [];
			$unitPayments  = isset($firstUnit['payments']) && is_array($firstUnit['payments']) ? $firstUnit['payments'] : [];
			$refundLinks   = isset($unitPayments['refunds']) && is_array($unitPayments['refunds']) ? $unitPayments['refunds'] : [];
			
			if (empty($refundLinks)) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => []];
			}
			
			// Step 5: Fetch each refund individually — the stubs only contain an ID and a link,
			// not the full details (amount, currency, status) needed by the caller
			$refunds = [];
			
			foreach ($refundLinks as $refundStub) {
				if (!is_array($refundStub)) {
					continue;
				}
				
				if (!isset($refundStub['id']) || !is_string($refundStub['id'])) {
					continue;
				}
				
				$result = $this->sendRequest('GET', '/v2/payments/refunds/' . urlencode($refundStub['id']));
				
				if ($result['request']['result'] === 0) {
					return $result;
				}
				
				if (isset($result['response'])) {
					$refunds[] = $result['response'];
				}
			}
			
			return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => ["refunds" => $refunds]];
		}
		
		/**
		 * Verifies a PayPal webhook notification by validating its signature headers.
		 * Replaces the NVP IPN echo-back verification mechanism.
		 * The webhook_id from your PayPal app settings is required to prevent replay attacks
		 * from other apps sending genuine but unrelated PayPal webhook payloads.
		 * @see https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature_post
		 * @param array<string, mixed> $headers The HTTP request headers (lowercased keys expected)
		 * @param string $rawBody The raw, unmodified request body string
		 * @return bool True if the webhook is genuine, false otherwise
		 */
		public function verifyWebhookSignature(array $headers, string $rawBody): bool {
			// PayPal's signature verification requires these five headers.
			// If any are missing the payload is malformed — reject immediately.
			$required = [
				'paypal-auth-algo',
				'paypal-cert-url',
				'paypal-transmission-id',
				'paypal-transmission-sig',
				'paypal-transmission-time',
			];
			
			// Validate all headers are present
			foreach ($required as $key) {
				if (empty($headers[$key])) {
					return false;
				}
			}
			
			// Build body
			$body = [
				'auth_algo'         => $headers['paypal-auth-algo'],
				
				// PayPal validates cert_url server-side (only their own cert domains are accepted),
				// so SSRF via a spoofed cert_url header is mitigated by PayPal's own verification API.
				// If you ever switch to local signature verification, validate cert_url against
				// an allowlist of PayPal cert domains before fetching it.
				'cert_url'          => $headers['paypal-cert-url'],
				'transmission_id'   => $headers['paypal-transmission-id'],
				'transmission_sig'  => $headers['paypal-transmission-sig'],
				'transmission_time' => $headers['paypal-transmission-time'],
				'webhook_id'        => $this->m_webhook_id,
				'webhook_event'     => json_decode($rawBody, true),
			];
			
			// Call API to verify webhook signature
			$result = $this->sendRequest('POST', '/v1/notifications/verify-webhook-signature', $body);
			
			// If this call failed, return false
			if ($result['request']['result'] === 0) {
				return false;
			}
			
			// PayPal returns "SUCCESS" or "FAILURE"
			return ($result['response']['verification_status'] ?? '') === 'SUCCESS';
		}
		
		/**
		 * Returns a valid OAuth2 access token, refreshing it if expired.
		 * PayPal access tokens expire after 32400 seconds (9 hours) but we treat
		 * them as expired 60 seconds early to avoid clock-skew edge cases.
		 * @return GatewayResponse
		 */
		private function getAccessToken(): array {
			// Return cached access token if there is one
			if ($this->m_access_token !== null && time() < $this->m_token_expires) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => ['accessToken' => $this->m_access_token]];
			}
			
			// Fetch fresh access token and cache it
			try {
				// Call the gateway
				$response = $this->m_client->request('POST', $this->m_base_url . '/v1/oauth2/token', [
					'auth_basic'  => [$this->m_client_id, $this->m_client_secret],
					'body'        => ['grant_type' => 'client_credentials'],
					'verify_peer' => $this->m_verify_ssl,
				]);
				
				// Fetch return data as array
				$data = $response->toArray(false);
				
				// If no access_token in response, return failure
				if (empty($data['access_token'])) {
					$error = $data['error_description'] ?? $data['error'] ?? 'Unexpected response from PayPal OAuth2 endpoint';
					return ['request' => ['result' => 0, 'errorId' => 'OAUTH2_FAILURE', 'errorMessage' => $error]];
				}
				
				// Cache the token and expires date
				$this->m_access_token  = $data['access_token'];
				$this->m_token_expires = time() + $data['expires_in'] - 60;
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => ['accessToken' => $this->m_access_token]];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => 'OAUTH2_FAILURE', 'errorMessage' => "PayPal authentication failed: {$e->getMessage()}"]];
			}
		}
		
		/**
		 * Send an authenticated REST request and return a normalized response array.
		 * All API methods funnel through here to keep HTTP handling in one place.
		 * @param string $method HTTP method: GET, POST, PATCH
		 * @param string $path API path, e.g. /v2/checkout/orders
		 * @param array<string, mixed> $body Request body (JSON-encoded), empty for GET
		 * @param array<string, mixed> $headers Extra headers to merge in
		 * @return GatewayResponse
		 */
		private function sendRequest(string $method, string $path, array $body = [], array $headers = []): array {
			try {
				// Fetch the access token
				$tokenResult = $this->getAccessToken();
				
				// If that failed, return the api error response
				if ($tokenResult['request']['result'] === 0) {
					return $tokenResult;
				}
				
				// Fetch the response
				$accessTokenResponse = isset($tokenResult['response']) && is_array($tokenResult['response']) ? $tokenResult['response'] : [];
				
				// Validate that the accessToken is a non-empty string. If not, show error to user
				$accessToken = isset($accessTokenResponse['accessToken']) && is_string($accessTokenResponse['accessToken']) ? $accessTokenResponse['accessToken'] : '';
				
				if (empty($accessToken)) {
					return ['request' => ['result' => 0, 'errorId' => '500', 'errorMessage' => "Invalid gateway response. Missing access token"]];
				}
				
				// Call the method the user desires using the access token
				$options = [
					'headers'     => array_merge([
						'Authorization' => "Bearer {$accessToken}",
						'Content-Type'  => 'application/json',
						'Prefer'        => 'return=representation',
					], $headers),
					'verify_peer' => $this->m_verify_ssl,
				];
				
				// Add JSON body if desired
				if (!empty($body)) {
					$options['json'] = $body;
				}
				
				// Call the gateway
				$response = $this->m_client->request($method, $this->m_base_url . $path, $options);
				$data     = $response->toArray(false);
				$status   = $response->getStatusCode();
				
				// 2xx = success
				if ($status >= 200 && $status < 300) {
					return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $data];
				}
				
				// PayPal REST error body: {"name": "...", "message": "...", "details": [...]}
				$errorName    = $data['name'] ?? 'UNKNOWN_ERROR';
				$errorMessage = $data['message'] ?? 'Unknown error';
				
				// Include the first detail entry if present — it usually carries the actionable reason
				if (!empty($data['details'][0]['issue'])) {
					$errorMessage .= ': ' . $data['details'][0]['issue'];
					
					if (!empty($data['details'][0]['description'])) {
						$errorMessage .= ' — ' . $data['details'][0]['description'];
					}
				}
				
				return ['request' => ['result' => 0, 'errorId' => $errorName, 'errorMessage' => $errorMessage], 'response' => $data];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => (string)$e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
	}