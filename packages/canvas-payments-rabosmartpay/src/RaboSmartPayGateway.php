<?php
	
	namespace Quellabs\Payments\RaboSmartPay;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the Rabo Smart Pay REST API (OmniKassa v2).
	 * Handles raw HTTP communication, token lifecycle, and response normalisation.
	 *
	 * All public methods return a normalised array in one of two shapes:
	 *
	 *   Success: ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   Failure: ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * Authentication: two-step Bearer token flow.
	 *   1. Refresh call: GET /gatekeeper/refresh with the long-lived refresh token.
	 *      Returns a short-lived access token valid for a single order announce session.
	 *   2. Order announce: POST /order/server/api/v2/order with the access token.
	 *
	 * The access token is cached in-process; a new one is fetched when the cached
	 * token is absent or expired (validity is indicated by the API response).
	 *
	 * Environments:
	 *   Production: https://api.pay.rabobank.nl/omnikassa-api
	 *   Sandbox:    https://betalen.rabobank.nl/omnikassa-api-sandbox
	 *
	 * Webhook integrity: Rabo Smart Pay signs webhook notifications and return URL
	 * query strings using HMAC-SHA512 with the base64-decoded signing key. Callers
	 * must verify signatures before processing any status data.
	 *
	 * @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
	 * @see https://github.com/rabobank-nederland/omnikassa-sdk-doc
	 */
	class RaboSmartPayGateway {
		
		/** @var string Production base URL */
		private const BASE_URL_LIVE = 'https://api.pay.rabobank.nl/omnikassa-api';
		
		/** @var string Sandbox base URL */
		private const BASE_URL_SANDBOX = 'https://betalen.rabobank.nl/omnikassa-api-sandbox';
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/** @var string Active base URL (live or sandbox), selected from config */
		private string $baseUrl;
		
		/** @var string Long-lived refresh token from the Rabo Smart Pay dashboard */
		private string $refreshToken;
		
		/** @var string|null Cached short-lived access token, null when not yet fetched or expired */
		private ?string $accessToken = null;
		
		/** @var int Unix timestamp when the cached access token expires, 0 when unknown */
		private int $accessTokenExpiry = 0;
		
		/**
		 * Constructs the gateway, extracting credentials from the driver config.
		 *
		 * @param Driver $driver Provider instance with active configuration already applied
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			// Select the base URL based on test_mode so all subsequent calls are routed correctly.
			$this->baseUrl = !empty($config['test_mode']) ? self::BASE_URL_SANDBOX : self::BASE_URL_LIVE;
			
			// Store the long-lived refresh token used to obtain access tokens.
			$this->refreshToken = $config['refresh_token'] ?? '';
			
			// Instantiate a shared Symfony HTTP client for all requests.
			$this->client = HttpClient::create();
		}
		
		/**
		 * Announces (creates) a new order at Rabo Smart Pay and returns a redirect URL.
		 *
		 * Rabo Smart Pay expects amount.amount in euro cents as an integer string.
		 * On success, response.redirectUrl points to the hosted checkout page.
		 * response.omnikassaOrderId is the UUID to use for status and refund calls.
		 *
		 * @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		 * @param array $payload Full order payload per Rabo Smart Pay spec
		 * @return array Normalised response
		 */
		public function announceOrder(array $payload): array {
			// Ensure we have a valid access token before the announce call.
			$tokenResult = $this->ensureAccessToken();
			
			if ($tokenResult['request']['result'] === 0) {
				return $tokenResult;
			}
			
			return $this->request('POST', '/order/server/api/v2/order', $payload);
		}
		
		/**
		 * Fetches the current status of an order by omnikassaOrderId.
		 *
		 * This endpoint should only be used as a fallback when a webhook notification
		 * has not been received. Polling is explicitly prohibited by Rabo Smart Pay.
		 * The primary status mechanism is the webhook + Status Pull flow.
		 *
		 * @see https://github.com/rabobank-nederland/omnikassa-sdk-doc
		 * @param string $omnikassaOrderId The UUID assigned by Rabo Smart Pay at announce time
		 * @return array Normalised response
		 */
		public function getOrderStatus(string $omnikassaOrderId): array {
			$tokenResult = $this->ensureAccessToken();
			
			if ($tokenResult['request']['result'] === 0) {
				return $tokenResult;
			}
			
			return $this->request('GET', '/order/server/api/v2/orders/' . urlencode($omnikassaOrderId));
		}
		
		/**
		 * Performs the Status Pull call using the token from a webhook notification.
		 *
		 * When Rabo Smart Pay POSTs a webhook notification, it includes an authentication
		 * token in the JSON body. This token is used — not the standard access token — to
		 * pull the batch of updated order statuses. The response may indicate
		 * moreOrderResultsAvailable=true, meaning another pull is needed.
		 *
		 * @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		 * @param string $notificationToken The authentication token from the webhook notification body
		 * @return array Normalised response containing orderResults[]
		 */
		public function pullOrderStatuses(string $notificationToken): array {
			return $this->request(
				'GET',
				'/order/server/api/events/results/merchant.order.status.changed',
				null,
				$notificationToken
			);
		}
		
		/**
		 * Issues a full or partial refund for a completed order.
		 *
		 * Sending a payload without an amount triggers a full refund.
		 * Sending a payload with amount.amount in euro cents triggers a partial refund.
		 * Rabo Smart Pay processes refunds asynchronously.
		 *
		 * @see https://docs.developer.rabobank.com/smartpay/reference/create-refund
		 * @param string $omnikassaOrderId The UUID of the original order
		 * @param array $payload May contain: amount.currency, amount.amount (in cents as string), description
		 * @return array Normalised response
		 */
		public function refundOrder(string $omnikassaOrderId, array $payload): array {
			$tokenResult = $this->ensureAccessToken();
			
			if ($tokenResult['request']['result'] === 0) {
				return $tokenResult;
			}
			
			return $this->request(
				'POST',
				'/order/server/api/v2/order/' . urlencode($omnikassaOrderId) . '/refund',
				$payload
			);
		}
		
		/**
		 * Verifies the HMAC-SHA512 signature on a webhook notification or return URL.
		 *
		 * Rabo Smart Pay signs data by concatenating the relevant fields with comma
		 * separators, then computing HMAC-SHA512 using the base64-decoded signing key.
		 * The computed signature is compared in constant time to prevent timing attacks.
		 *
		 * @param string $payload Comma-joined field values exactly as documented
		 * @param string $providedSignature Hex-encoded signature from the notification or URL
		 * @param string $signingKey Base64-encoded signing key from the dashboard
		 * @return bool True when the signature is valid
		 */
		public function verifySignature(string $payload, string $providedSignature, string $signingKey): bool {
			// Decode the signing key from base64 — the raw bytes are the HMAC key.
			$keyBytes = base64_decode($signingKey, strict: true);
			
			if ($keyBytes === false) {
				return false;
			}
			
			// Compute HMAC-SHA512 over the UTF-8 encoded payload using the raw key bytes.
			$computed = hash_hmac('sha512', $payload, $keyBytes);
			
			// Constant-time comparison prevents timing-based signature forgery.
			return hash_equals($computed, strtolower($providedSignature));
		}
		
		/**
		 * Ensures a valid access token is available for API calls.
		 *
		 * The access token is cached in-process. A new token is fetched only when the
		 * cached one is absent or within 60 seconds of expiry (clock-skew buffer).
		 * Callers should not obtain a new token for every individual API call.
		 *
		 * Refresh endpoint: GET /gatekeeper/refresh
		 * Authorization: Bearer {refresh_token}
		 * Response: { token: string, validUntil: ISO8601 }
		 *
		 * @return array Normalised success or failure response
		 */
		private function ensureAccessToken(): array {
			// If we have a cached token with >60s remaining, reuse it.
			if ($this->accessToken !== null && time() < ($this->accessTokenExpiry - 60)) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => '']];
			}
			
			try {
				// The refresh call uses the long-lived refresh token, not the access token.
				$response = $this->client->request('GET', $this->baseUrl . '/gatekeeper/refresh', [
					'headers' => [
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $this->refreshToken,
					],
				]);
				
				$statusCode = $response->getStatusCode();
				$body = json_decode($response->getContent(false), true);
				
				if (json_last_error() !== JSON_ERROR_NONE) {
					return [
						'request' => [
							'result'       => 0,
							'errorId'      => $statusCode,
							'errorMessage' => 'Invalid JSON from gatekeeper: ' . json_last_error_msg(),
						],
					];
				}
				
				if ($statusCode !== 200 || empty($body['token'])) {
					$msg = $body['consumerMessage'] ?? ($body['errorMessage'] ?? "HTTP {$statusCode}");
					
					return [
						'request' => [
							'result'       => 0,
							'errorId'      => $statusCode,
							'errorMessage' => 'Token refresh failed: ' . $msg,
						],
					];
				}
				
				// Cache the access token and parse its expiry time.
				$this->accessToken = $body['token'];
				
				// validUntil is an ISO 8601 timestamp e.g. "2025-01-01T12:00:00.000+01:00"
				$this->accessTokenExpiry = isset($body['validUntil'])
					? (int)strtotime($body['validUntil'])
					: time() + 300; // 5-minute fallback when field is absent
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => '']];
			} catch (\Throwable $e) {
				return [
					'request' => [
						'result'       => 0,
						'errorId'      => $e->getCode(),
						'errorMessage' => 'Token refresh exception: ' . $e->getMessage(),
					],
				];
			}
		}
		
		/**
		 * Sends an authenticated request to the Rabo Smart Pay REST API and returns a
		 * normalised result array. All public gateway methods delegate to this.
		 *
		 * @param string $method HTTP method: GET or POST
		 * @param string $endpoint Path relative to baseUrl, e.g. '/order/server/api/v2/order'
		 * @param array|null $payload JSON body for POST; null for GET
		 * @param string|null $bearerToken Override the cached access token (used for Status Pull)
		 * @return array Normalised response
		 */
		private function request(string $method, string $endpoint, ?array $payload = null, ?string $bearerToken = null): array {
			try {
				// Use the provided bearer token (webhook notification token) or the cached access token.
				$token = $bearerToken ?? $this->accessToken;
				
				$options = [
					'headers' => [
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $token,
					],
				];
				
				if ($payload !== null) {
					$options['headers']['Content-Type'] = 'application/json';
					$options['json'] = $payload;
				}
				
				$response = $this->client->request($method, $this->baseUrl . $endpoint, $options);
				$statusCode = $response->getStatusCode();
				
				// Decode with throw-on-error disabled so we can inspect error bodies.
				$body = json_decode($response->getContent(false), true);
				
				if (json_last_error() !== JSON_ERROR_NONE) {
					return [
						'request' => [
							'result'       => 0,
							'errorId'      => $statusCode,
							'errorMessage' => 'Invalid JSON response: ' . json_last_error_msg(),
						],
					];
				}
				
				if ($statusCode >= 200 && $statusCode < 300) {
					return [
						'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
						'response' => $body,
					];
				}
				
				// Extract the most informative error message from the error body.
				$errorMessage = $this->extractErrorMessage($body, $statusCode);
				
				return [
					'request' => [
						'result'       => 0,
						'errorId'      => $statusCode,
						'errorMessage' => $errorMessage,
					],
				];
			} catch (\Throwable $e) {
				return [
					'request' => [
						'result'       => 0,
						'errorId'      => $e->getCode(),
						'errorMessage' => $e->getMessage(),
					],
				];
			}
		}
		
		/**
		 * Extracts the most useful human-readable error message from a Rabo Smart Pay error body.
		 *
		 * Rabo Smart Pay uses a consumerMessage / errorMessage / errorCode structure:
		 *   { "errorCode": "AUTH_001", "consumerMessage": "...", "errorMessage": "..." }
		 *
		 * @param array|null $body Decoded JSON body, or null if the body was empty
		 * @param int $statusCode HTTP status code, used as fallback error identifier
		 * @return string Human-readable error message
		 */
		private function extractErrorMessage(?array $body, int $statusCode): string {
			if ($body === null) {
				return "HTTP {$statusCode}";
			}
			
			// Prefer the consumer-facing message when available.
			if (!empty($body['consumerMessage'])) {
				return $body['consumerMessage'];
			}
			
			// Fall back to the technical error message with the error code prefix.
			if (!empty($body['errorMessage'])) {
				$msg = $body['errorMessage'];
				
				if (!empty($body['errorCode'])) {
					$msg = $body['errorCode'] . ': ' . $msg;
				}
				
				return $msg;
			}
			
			return "HTTP {$statusCode}";
		}
	}