<?php
	
	namespace Quellabs\Payments\PayNL;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the Pay.nl REST API (TGU v1).
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 *
	 * All public methods accept plain arrays and return a normalised array in one of
	 * two shapes — callers check result to determine success or failure:
	 *
	 *   Success: ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   Failure: ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * Authentication: HTTP Basic Auth using the token code (AT-xxxx) as the username
	 * and the 40-character API token as the password, base64-encoded per RFC 7617.
	 * Credentials are found in the Pay.nl admin under Merchant → Company information.
	 *
	 * Test vs live: a single endpoint handles both environments. Test mode is communicated
	 * per-order via integration.test=true in the request body.
	 *
	 * HTTP status codes: Pay.nl uses proper HTTP semantics — 200/201 for success,
	 * 400/401/403/404/422 for errors. Error details are in the response body as
	 * { violations: [...] } or { message: '...' }.
	 *
	 * @see https://developer.pay.nl/docs/api-defenition
	 * @see https://developer.pay.nl/reference/api_create_order-1
	 */
	class PayNLGateway {
		
		/** @var string Base URL for all Pay.nl TGU v1 API calls */
		private const BASE_URL = 'https://connect.pay.nl/v1';
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/** @var string Precomputed Basic Auth header value sent on every request */
		private string $authHeader;
		
		/**
		 * Constructs the gateway, extracting credentials from the driver config.
		 *
		 * The auth header is built once here rather than per-request to avoid repeating
		 * the base64 encoding on every call.
		 *
		 * @param Driver $driver Provider instance with active configuration already applied
		 */
		public function __construct(Driver $driver) {
			// Pull the resolved configuration (defaults merged with loaded values).
			$config = $driver->getConfig();
			
			// Instantiate a shared Symfony HTTP client for all requests from this gateway.
			$this->client = HttpClient::create();
			
			// Precompute the Basic Auth header: base64("AT-xxxx-xxxx:40chartoken").
			// token_code is the username; api_token is the password.
			$this->authHeader = 'Basic ' . base64_encode(
					($config['token_code'] ?? '') . ':' . ($config['api_token'] ?? '')
				);
		}
		
		/**
		 * Creates a new Pay.nl order and returns the normalised response.
		 *
		 * Expected response fields on success:
		 *   response.id             — UUID; the authoritative identifier for status and refund calls
		 *   response.orderId        — legacy human-readable reference (e.g. "51007856048X14b0")
		 *   response.links.redirect — URL to redirect the shopper to
		 *   response.links.status   — URL to poll for order status
		 *
		 * @see https://developer.pay.nl/reference/api_create_order-1
		 * @param array $payload Full order payload per Pay.nl spec
		 * @return array Normalised response
		 */
		public function createOrder(array $payload): array {
			return $this->request('POST', '/orders', $payload);
		}
		
		/**
		 * Fetches the current status of an order by UUID.
		 *
		 * The response is the authoritative source for both the return URL and exchange
		 * webhook flows. It embeds all payment attempts in a payments[] array, including
		 * refund entries identified by negative status codes (-81 full, -82 partial).
		 *
		 * @see https://developer.pay.nl/reference/api_get_status-1
		 * @param string $orderId The order UUID (id field from createOrder response)
		 * @return array Normalised response
		 */
		public function getOrderStatus(string $orderId): array {
			// The order UUID goes in the path — URL-encode to handle any special characters.
			return $this->request('GET', '/orders/' . urlencode($orderId) . '/status');
		}
		
		/**
		 * Issues a full or partial refund for a completed order.
		 *
		 * Sending a payload without an amount key triggers a full refund.
		 * Sending a payload with amount.value in minor units triggers a partial refund.
		 *
		 * Pay.nl processes refunds asynchronously. The exchange URL receives a refund:add
		 * notification immediately, followed by refund:received when the bank settles.
		 *
		 * @see https://developer.pay.nl/docs/refund
		 * @param string $orderId The order UUID of the original payment
		 * @param array $payload May contain: amount.value, amount.currency, description
		 * @return array Normalised response containing the updated order object
		 */
		public function refundOrder(string $orderId, array $payload): array {
			// PATCH updates the existing order in place rather than creating a new resource.
			return $this->request('PATCH', '/orders/' . urlencode($orderId), $payload);
		}
		
		/**
		 * Sends an authenticated request to the Pay.nl TGU REST API and returns a
		 * normalised result array. All public gateway methods delegate to this.
		 *
		 * Pay.nl uses standard HTTP status codes — 2xx for success, 4xx/5xx for errors.
		 * The JSON body is always decoded regardless of the HTTP status because error
		 * detail (violations, message) lives in the body, not the status line.
		 *
		 * @see https://developer.pay.nl/docs/api-defenition
		 * @param string $method HTTP method: GET, POST, or PATCH
		 * @param string $endpoint Path relative to BASE_URL, e.g. '/orders'
		 * @param array|null $payload JSON body for POST/PATCH; null for GET
		 * @return array Normalised response
		 */
		private function request(string $method, string $endpoint, ?array $payload = null): array {
			try {
				// Start with the headers required on every request.
				$options = [
					'headers' => [
						'Accept'        => 'application/json',
						'Authorization' => $this->authHeader,
					],
				];
				
				// Attach the JSON body and content-type header for write operations.
				// GET requests send no body.
				if ($payload !== null) {
					$options['headers']['Content-Type'] = 'application/json';
					$options['json'] = $payload;
				}
				
				// Execute the HTTP request against the full endpoint URL.
				$response = $this->client->request($method, self::BASE_URL . $endpoint, $options);
				
				// Read the HTTP status code before decoding the body.
				// Pay.nl uses 200 for successful GET and 201 for successful POST/PATCH.
				$statusCode = $response->getStatusCode();
				
				// Decode the body with throw-on-error disabled so we can inspect the
				// content of error responses rather than having the client throw for 4xx/5xx.
				$body = json_decode($response->getContent(false), true);
				
				// If the body could not be decoded as valid JSON, return a normalised error.
				// This happens on gateway timeouts or when a proxy returns an HTML error page.
				if (json_last_error() !== JSON_ERROR_NONE) {
					return [
						'request' => [
							'result'       => 0,
							'errorId'      => $statusCode,
							'errorMessage' => 'Invalid JSON response: ' . json_last_error_msg(),
						],
					];
				}
				
				// HTTP 2xx indicates the operation succeeded — return the decoded body.
				if ($statusCode >= 200 && $statusCode < 300) {
					return [
						'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
						'response' => $body,
					];
				}
				
				// HTTP 4xx/5xx — extract the most informative error message from the body
				// and return it in the normalised failure shape.
				$errorMessage = $this->extractErrorMessage($body, $statusCode);
				
				return [
					'request' => [
						'result'       => 0,
						'errorId'      => $statusCode,
						'errorMessage' => $errorMessage,
					],
				];
			} catch (\Throwable $e) {
				// Catch network-level exceptions (DNS failure, connection refused, TLS error)
				// and normalise them into the same failure shape as API-level errors.
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
		 * Extracts the most useful human-readable error message from a Pay.nl error body.
		 *
		 * Pay.nl uses two distinct error body shapes depending on the HTTP status code:
		 *
		 *   422 Unprocessable Entity:
		 *     { "violations": [{ "field": "amount.value", "message": "must be positive" }] }
		 *   All other 4xx/5xx:
		 *     { "message": "Not found" }
		 *     { "message": "Bad Request", "details": "serviceId is required" }
		 *   401/404 with no body:
		 *     (empty or null)
		 *
		 * @param array|null $body Decoded JSON body, or null if the body was empty
		 * @param int $statusCode HTTP status code, used as fallback error identifier
		 * @return string Human-readable error message suitable for exception messages
		 */
		private function extractErrorMessage(?array $body, int $statusCode): string {
			// Empty body — return the HTTP status code as a minimal error identifier.
			if ($body === null) {
				return "HTTP {$statusCode}";
			}
			
			// 422 responses carry a violations array with per-field error details.
			// Concatenate all violation messages into a single semicolon-delimited string.
			if (isset($body['violations']) && is_array($body['violations'])) {
				$messages = array_map(
					fn($v) => ($v['field'] ?? '') . ': ' . ($v['message'] ?? ''),
					$body['violations']
				);
				
				return implode('; ', $messages) ?: "HTTP {$statusCode}";
			}
			
			// Other error responses use a top-level message key, optionally supplemented
			// by a details key that provides more specific context.
			if (isset($body['message'])) {
				$msg = $body['message'];
				
				// Append the details field when present — it often names the specific
				// field or value that caused the rejection.
				if (isset($body['details'])) {
					$msg .= ' — ' . $body['details'];
				}
				
				return $msg;
			}
			
			// Body was present but contained neither violations nor message — fall back
			// to the HTTP status code.
			return "HTTP {$statusCode}";
		}
	}