<?php
	
	namespace Quellabs\Payments\Klarna;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the Klarna Payments, Hosted Payment Page (HPP),
	 * and Order Management REST APIs.
	 *
	 * All public methods return a normalised array in one of two shapes:
	 *
	 *   Success: ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   Failure: ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * Authentication: HTTP Basic — Base64(username:password) — stateless and per-request.
	 * No token caching is required; credentials never expire during a session.
	 *
	 * Integration flow (Hosted Payment Page, server-side only):
	 *   1. POST /payments/v1/sessions           → create KP session, get kp_session_id
	 *   2. POST /hpp/v1/sessions                → create HPP session, get redirect_url
	 *   3. Redirect consumer to redirect_url    → Klarna handles checkout UI
	 *   4. Consumer is redirected back to merchant_urls.success with ?order_id=
	 *   5. POST /ordermanagement/v1/orders/{id}/acknowledge → acknowledge the order
	 *   6. POST /ordermanagement/v1/orders/{id}/captures   → capture when goods ship
	 *      (omit step 6 when place_order_mode=CAPTURE_ORDER is used at HPP creation)
	 *
	 * Environments:
	 *   Production: https://api.klarna.com
	 *   Playground:  https://api.playground.klarna.com
	 *
	 * @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/
	 * @see https://docs.klarna.com/acquirer/klarna/api/payments/
	 * @see https://docs.klarna.com/acquirer/klarna/api/hpp-merchant/
	 * @see https://docs.klarna.com/acquirer/klarna/api/ordermanagement/
	 */
	class KlarnaGateway {
		
		/** @var string Production base URL */
		private const BASE_URL_LIVE = 'https://api.klarna.com';
		
		/** @var string Playground (test) base URL */
		private const BASE_URL_SANDBOX = 'https://api.playground.klarna.com';
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/** @var string Active base URL (live or sandbox), selected from config */
		private string $baseUrl;
		
		/** @var string Base64-encoded "username:password" credential string */
		private string $basicAuth;
		
		/**
		 * Constructs the gateway, extracting credentials from the driver config.
		 *
		 * @param Driver $driver Provider instance with active configuration already applied
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			// Select the base URL based on test_mode so all subsequent calls are routed correctly.
			$this->baseUrl = !empty($config['test_mode']) ? self::BASE_URL_SANDBOX : self::BASE_URL_LIVE;
			
			// Pre-encode the Basic Auth credential to avoid re-encoding on every request.
			$this->basicAuth = base64_encode($config['api_username'] . ':' . $config['api_password']);
			
			// Instantiate a shared Symfony HTTP client for all requests.
			$this->client = HttpClient::create();
		}
		
		/**
		 * Creates a Klarna Payments session (KP session).
		 *
		 * This is the first step in the HPP flow. The KP session carries the order
		 * details and returns a session_id used to create the HPP session.
		 *
		 * Required fields in $payload:
		 *   - purchase_country  (ISO 3166-1 alpha-2, e.g. 'NL')
		 *   - purchase_currency (ISO 4217, e.g. 'EUR')
		 *   - locale            (BCP 47, e.g. 'nl-NL')
		 *   - order_amount      (integer, in minor units / cents)
		 *   - order_tax_amount  (integer, in minor units / cents)
		 *   - order_lines       (array of line item objects)
		 *   - intent            ('buy' for one-time payments)
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-sdk/step-1-initiate-a-payment/
		 * @param array $payload KP session payload per Klarna spec
		 * @return array Normalised response containing session_id and client_token
		 */
		public function createPaymentSession(array $payload): array {
			return $this->request('POST', '/payments/v1/sessions', $payload);
		}
		
		/**
		 * Creates a Hosted Payment Page (HPP) session linked to a KP session.
		 *
		 * The HPP session wraps the KP session and provides a redirect_url that the
		 * consumer is redirected to. All checkout UI is handled by Klarna on the HPP.
		 *
		 * Required fields in $payload:
		 *   - payment_session_url  Full URL to the KP session:
		 *                          "{baseUrl}/payments/v1/sessions/{kp_session_id}"
		 *   - merchant_urls.success  Return URL on successful payment (may include
		 *                            {{order_id}} and {{authorization_token}} placeholders)
		 *   - merchant_urls.failure  Return URL on Klarna rejection
		 *   - merchant_urls.cancel   Return URL on consumer cancellation
		 *   - merchant_urls.error    Return URL on fatal error
		 *   - place_order_mode       'NONE' | 'PLACE_ORDER' | 'CAPTURE_ORDER'
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/api-documentation/create-session/
		 * @param array $payload HPP session payload per Klarna spec
		 * @return array Normalised response containing session_id, redirect_url, expires_at
		 */
		public function createHppSession(array $payload): array {
			return $this->request('POST', '/hpp/v1/sessions', $payload);
		}
		
		/**
		 * Reads the current status of an HPP session.
		 *
		 * Used to verify payment outcome when the consumer returns via the success URL.
		 * Possible status values: WAITING, IN_PROGRESS, COMPLETED, DISABLED, EXPIRED, ERROR.
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/api-documentation/read-session/
		 * @param string $hppSessionId The HPP session_id returned at creation
		 * @return array Normalised response containing status and, when COMPLETED, order_id
		 */
		public function readHppSession(string $hppSessionId): array {
			return $this->request('GET', '/hpp/v1/sessions/' . urlencode($hppSessionId));
		}
		
		/**
		 * Retrieves the full details of an order from the Order Management API.
		 *
		 * Returns authoritative order state (status, captured_amount, refunded_amount).
		 * Use this to verify payment outcome server-side rather than trusting query params.
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/after-payments/order-management/manage-orders-with-the-api/view-and-change-orders/
		 * @param string $orderId The Klarna order_id (UUID)
		 * @return array Normalised response containing order details
		 */
		public function getOrder(string $orderId): array {
			return $this->request('GET', '/ordermanagement/v1/orders/' . urlencode($orderId));
		}
		
		/**
		 * Acknowledges an order in the Order Management API.
		 *
		 * Required after the consumer is redirected to merchant_urls.success when
		 * place_order_mode is NONE or PLACE_ORDER. Not required for CAPTURE_ORDER
		 * because capture implies acknowledgement.
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/after-payments/order-management/manage-orders-with-the-api/view-and-change-orders/
		 * @param string $orderId The Klarna order_id (UUID)
		 * @return array Normalised response (204 No Content maps to empty 'response' array)
		 */
		public function acknowledgeOrder(string $orderId): array {
			return $this->request('POST', '/ordermanagement/v1/orders/' . urlencode($orderId) . '/acknowledge');
		}
		
		/**
		 * Captures an order (or a partial amount) in the Order Management API.
		 *
		 * Must be called when goods are shipped. For CAPTURE_ORDER mode, capture is
		 * automatic — do not call this method in that case.
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/after-payments/order-management/manage-orders-with-the-api/capture-and-track-orders/
		 * @param string $orderId        The Klarna order_id (UUID)
		 * @param int    $capturedAmount Amount in minor units (cents) to capture
		 * @param string $idempotencyKey UUID v4; prevents duplicate capture on retry
		 * @return array Normalised response containing capture_id
		 */
		public function captureOrder(string $orderId, int $capturedAmount, string $idempotencyKey): array {
			return $this->request(
				'POST',
				'/ordermanagement/v1/orders/' . urlencode($orderId) . '/captures',
				['captured_amount' => $capturedAmount],
				['Klarna-Idempotency-Key' => $idempotencyKey]
			);
		}
		
		/**
		 * Issues a full or partial refund for a captured order.
		 *
		 * Klarna processes refunds asynchronously. The response on success is 201 Created.
		 * For full refunds, pass the full captured_amount. For partial refunds, pass less.
		 *
		 * @see https://docs.klarna.com/acquirer/klarna/after-payments/order-management/manage-orders-with-the-api/refund-orders-and-manage-authorizations/
		 * @param string      $orderId         The Klarna order_id (UUID)
		 * @param int         $refundedAmount  Amount to refund in minor units (cents)
		 * @param string      $idempotencyKey  UUID v4; prevents duplicate refund on retry
		 * @param string|null $description     Optional description shown to the customer
		 * @return array Normalised response containing refund_id
		 */
		public function refundOrder(string $orderId, int $refundedAmount, string $idempotencyKey, ?string $description = null): array {
			$payload = array_filter([
				'refunded_amount' => $refundedAmount,
				'description'     => $description,
			], fn($v) => $v !== null && $v !== '');
			
			return $this->request(
				'POST',
				'/ordermanagement/v1/orders/' . urlencode($orderId) . '/refunds',
				$payload,
				['Klarna-Idempotency-Key' => $idempotencyKey]
			);
		}
		
		/**
		 * Sends an authenticated request to the Klarna REST API and returns a
		 * normalised result array. All public gateway methods delegate to this.
		 *
		 * Klarna returns 201 for creates (captures, refunds) and 204 for no-content
		 * responses (acknowledge). Both are treated as success. 2xx bodies may be
		 * empty (acknowledge/capture) or JSON (order details, session data).
		 *
		 * @param string      $method      HTTP method: GET or POST
		 * @param string      $endpoint    Path relative to baseUrl
		 * @param array|null  $payload     JSON body for POST; null for GET or body-less POST
		 * @param array       $extraHeaders Additional headers (e.g. Klarna-Idempotency-Key)
		 * @return array Normalised response
		 */
		private function request(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array {
			try {
				$headers = array_merge([
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . $this->basicAuth,
				], $extraHeaders);
				
				$options = ['headers' => $headers];
				
				if ($payload !== null) {
					$options['headers']['Content-Type'] = 'application/json';
					$options['json'] = $payload;
				}
				
				$response = $this->client->request($method, $this->baseUrl . $endpoint, $options);
				$statusCode = $response->getStatusCode();
				
				// 204 No Content is a valid success with no body (acknowledge, etc.)
				if ($statusCode === 204) {
					return [
						'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
						'response' => [],
					];
				}
				
				// Decode with throw-on-error disabled so we can inspect error bodies.
				$rawBody = $response->getContent(false);
				
				// Some 201 responses (captures) have an empty body; treat as success.
				if ($statusCode === 201 && empty($rawBody)) {
					return [
						'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
						'response' => [],
					];
				}
				
				$body = json_decode($rawBody, true);
				
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
						'response' => $body ?? [],
					];
				}
				
				// Extract the most informative error message from the Klarna error body.
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
		 * Extracts the most useful error message from a Klarna API error body.
		 *
		 * Klarna uses: { "error_code": "...", "error_messages": ["..."], "correlation_id": "..." }
		 * Some endpoints use: { "error_code": "...", "error_message": "..." }
		 *
		 * @param array|null $body       Decoded JSON body, or null if the body was empty
		 * @param int        $statusCode HTTP status code, used as fallback
		 * @return string Human-readable error message
		 */
		private function extractErrorMessage(?array $body, int $statusCode): string {
			if ($body === null) {
				return "HTTP {$statusCode}";
			}
			
			// Prefer the array of error messages (Order Management API style).
			if (!empty($body['error_messages']) && is_array($body['error_messages'])) {
				$msg = implode('; ', $body['error_messages']);
				
				if (!empty($body['error_code'])) {
					$msg = $body['error_code'] . ': ' . $msg;
				}
				
				return $msg;
			}
			
			// Fall back to singular error_message field (some endpoints use this).
			if (!empty($body['error_message'])) {
				$msg = $body['error_message'];
				
				if (!empty($body['error_code'])) {
					$msg = $body['error_code'] . ': ' . $msg;
				}
				
				return $msg;
			}
			
			return "HTTP {$statusCode}";
		}
	}