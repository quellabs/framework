<?php
	
	namespace Quellabs\Payments\MultiSafepay;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the MultiSafepay REST API (v1).
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * Authentication: the API key is passed as a header on every request.
	 * MSP does not use bearer tokens or a separate authentication endpoint.
	 *
	 * Test vs live: entirely separate base URLs — not a test flag on the same endpoint.
	 *
	 * @see https://docs.multisafepay.com/reference/introduction
	 */
	class MultiSafepayGateway {
		
		private const API_VERSION = 'v1';
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/** @var string Base URL selected from config at construction (test or live) */
		private string $baseUrl;
		
		/** @var string API key passed as query parameter on every request */
		private string $apiKey;
		
		/**
		 * MultiSafepayGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			// Fetch configuration
			$config = $driver->getConfig();
			
			// Extract config info
			$this->client = HttpClient::create();
			$this->apiKey = $config['api_key'] ?? '';
			
			// MSP uses completely separate hostnames for test and live, not a flag on one endpoint.
			// @see https://docs.multisafepay.com/docs/test-your-integration
			if ($config['test_mode']) {
				$this->baseUrl = 'https://testapi.multisafepay.com/' . self::API_VERSION;
			} else {
				$this->baseUrl = 'https://api.multisafepay.com/' . self::API_VERSION;
			}
		}
		
		/**
		 * Creates a new MultiSafepay order (redirect type).
		 * Returns an order_id and a payment_url for redirecting the shopper.
		 * @see https://docs.multisafepay.com/reference/createorder
		 * @param array $payload Full order payload per MSP spec
		 * @return array Normalised response
		 */
		public function createOrder(array $payload): array {
			return $this->request('POST', '/orders', $payload);
		}
		
		/**
		 * Fetches the current state of an order.
		 * This is the authoritative status source for both the return URL and webhook flows —
		 * MSP's notification body contains only the order_id, not the status.
		 * @see https://docs.multisafepay.com/reference/getorder
		 * @param string $orderId The order_id used when the order was created (your reference)
		 * @return array Normalised response
		 */
		public function getOrder(string $orderId): array {
			return $this->request('GET', '/orders/' . urlencode($orderId));
		}
		
		/**
		 * Submits a refund for a completed order.
		 * Supports both full and partial refunds — the distinction is solely in the amount.
		 * MSP processes the refund immediately; a 'refunded' or 'partial_refunded' status
		 * is reflected on the order after processing.
		 * @see https://docs.multisafepay.com/reference/refundorder
		 * @param string $orderId The order_id of the original payment
		 * @param array $payload Must include currency and amount (in minor units)
		 * @return array Normalised response containing the refund transaction_id
		 */
		public function refundOrder(string $orderId, array $payload): array {
			return $this->request('POST', '/orders/' . urlencode($orderId) . '/refunds', $payload);
		}
		
		/**
		 * Retrieves the list of iDEAL issuers (participating banks).
		 * The gateway parameter is the MSP gateway code in lowercase, e.g. 'ideal'.
		 * Only iDEAL currently uses a dedicated issuer endpoint; other methods do not.
		 * @see https://docs.multisafepay.com/reference/issuers
		 * @param string $gateway Lowercase MSP gateway code (e.g. 'ideal')
		 * @return array Normalised response with 'data' containing issuer objects
		 */
		public function getIssuers(string $gateway): array {
			return $this->request('GET', '/issuers/' . urlencode($gateway));
		}
		
		/**
		 * Sends an authenticated request to the MSP REST API.
		 *
		 * MSP always returns HTTP 200, even for errors. The actual success/failure is indicated
		 * by the top-level 'success' boolean in the JSON body, with 'error_code' and
		 * 'error_info' describing any failure. The HTTP status code alone is not reliable — the body must always be inspected.
		 *
		 * @see https://docs.multisafepay.com/docs/errors
		 * @param string $method HTTP method (e.g. 'GET', 'POST')
		 * @param string $endpoint Path relative to the versioned base URL
		 * @param array|null $payload JSON request body (POST only)
		 * @return array Normalised response
		 */
		private function request(string $method, string $endpoint, ?array $payload = null): array {
			try {
				$options = [
					'headers' => [
						'Accept'  => 'application/json',
						'api_key' => $this->apiKey,
					],
				];

				if ($payload !== null) {
					$options['headers']['Content-Type'] = 'application/json';
					$options['json'] = $payload;
				}
				
				$response = $this->client->request($method, $this->baseUrl . $endpoint, $options);

				// Decode the JSON body
				$body = json_decode($response->getContent(false), true);
				
				// If that failed, return error
				if (json_last_error() !== JSON_ERROR_NONE) {
					return ['request' => ['result' => 0, 'errorId' => 0, 'errorMessage' => 'Invalid JSON response: ' . json_last_error_msg()]];
				}
				
				// If success is false or absent, return error
				if (!($body['success'] ?? false)) {
					return ['request' => ['result' => 0, 'errorId' => $body['error_code'] ?? 0, 'errorMessage' => $body['error_info'] ?? 'Unknown error']];
				}
				
				// Return data
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $body];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
	}