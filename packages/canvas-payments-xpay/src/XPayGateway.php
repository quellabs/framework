<?php
	
	namespace Quellabs\Payments\XPay;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the Nexi XPay Global JSON API (phoenix-0.0).
	 * Handles raw HTTP communication, X-API-KEY authentication, and response normalisation.
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * Authentication: every request carries an X-API-KEY header and a Correlation-Id (UUID v4).
	 * There is no HMAC signing — the API key is the sole authentication mechanism for server-to-server calls.
	 *
	 * Test vs live: the same hostname is used for both environments; mode is determined by which
	 * API key is provided (test keys are issued separately from the XPay Back Office).
	 *
	 * Amounts in XPay are in the smallest currency unit (minor units): 50 EUR = 5000, 50 JPY = 50.
	 * This matches the contract convention — no conversion needed inside this class.
	 *
	 * Base URL: https://xpay.nexigroup.com/api/phoenix-0.0/psp/api/v1
	 *
	 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/
	 */
	class XPayGateway {
		
		/** @var string XPay Global API base URL */
		private const BASE_URL = 'https://xpay.nexigroup.com/api/phoenix-0.0/psp/api/v1';
		
		/** @var string XPay Global sandbox API base URL */
		private const BASE_URL_TEST = 'https://xpay.nexigroup.com/api/phoenix-0.0/psp/api/v1';
		
		/** @var string API key sent in the X-API-KEY header */
		private string $apiKey;
		
		/** @var string Resolved base URL (test or live — same host, key determines environment) */
		private string $baseUrl;
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/**
		 * XPayGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			$this->client  = HttpClient::create();
			$this->apiKey  = $config['api_key'] ?? '';
			$this->baseUrl = self::BASE_URL;
		}
		
		/**
		 * Creates a new order for the Hosted Payment Page (HPP).
		 *
		 * Returns a hostedPage URL to redirect the shopper to, plus a securityToken
		 * that should be stored and verified against the return URL and push notifications.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/#orders-hpp-post
		 * @param array $payload Full order payload per XPay spec
		 * @return array Normalised response
		 */
		public function createOrder(array $payload): array {
			return $this->request('POST', '/orders/hpp', $payload);
		}
		
		/**
		 * Retrieves the full status of an order by its orderId.
		 *
		 * The response contains an `operations` array listing all operations (CAPTURE, REFUND, etc.)
		 * associated with the order. This is the authoritative status source for return URL and push flows.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/#orders-orderid-get
		 * @param string $orderId Your order identifier (up to 27 chars)
		 * @return array Normalised response
		 */
		public function getOrder(string $orderId): array {
			return $this->request('GET', '/orders/' . urlencode($orderId));
		}
		
		/**
		 * Retrieves a specific operation by its operationId.
		 *
		 * Used to fetch details of a specific operation (e.g. a refund) by its XPay-assigned operationId.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/#operations-operationid-get
		 * @param string $operationId XPay-assigned operation identifier
		 * @return array Normalised response
		 */
		public function getOperation(string $operationId): array {
			return $this->request('GET', '/operations/' . urlencode($operationId));
		}
		
		/**
		 * Submits a refund for a captured operation.
		 *
		 * POST /operations/{operationId}/refunds
		 * The operationId must be that of a CAPTURE operation, not the orderId.
		 * Omitting the amount triggers a full refund.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/#operations-operationid-refunds-post
		 * @param string $operationId The CAPTURE operationId to refund against
		 * @param array $payload Refund payload; may contain 'amount' and 'currency' for partial refunds
		 * @return array Normalised response
		 */
		public function refundOperation(string $operationId, array $payload): array {
			return $this->request('POST', '/operations/' . urlencode($operationId) . '/refunds', $payload);
		}
		
		/**
		 * Retrieves the list of payment methods supported by this merchant.
		 * Used to populate the available payment method list dynamically.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/#paymentmethods-get
		 * @return array Normalised response
		 */
		public function getPaymentMethods(): array {
			return $this->request('GET', '/paymentmethods');
		}
		
		/**
		 * Builds and sends an X-API-KEY-authenticated request to the XPay Global JSON API.
		 *
		 * XPay always returns JSON. HTTP status codes are meaningful:
		 *   - 200/201: success
		 *   - 4xx/5xx: error; body contains errors[] array with code and description
		 *
		 * The Correlation-Id header is a UUID v4 required for tracing. XPay does not validate
		 * its uniqueness server-side but it must be present and well-formed.
		 *
		 * @param string $method HTTP method ('GET' or 'POST')
		 * @param string $path Path relative to the base URL (must start with '/')
		 * @param array|null $payload JSON request body (POST only; omit for GET)
		 * @return array Normalised response
		 */
		private function request(string $method, string $path, ?array $payload = null): array {
			try {
				$url = $this->baseUrl . $path;
				
				$options = [
					'headers' => [
						'Content-Type'   => 'application/json',
						'Accept'         => 'application/json',
						'X-API-KEY'      => $this->apiKey,
						'Correlation-Id' => $this->generateCorrelationId(),
					],
				];
				
				if ($payload !== null) {
					$options['body'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
				}
				
				$response = $this->client->request($method, $url, $options);
				$httpCode = $response->getStatusCode();
				$rawBody  = $response->getContent(false);
				
				// Empty body on success (e.g. 204 No Content) — treat as success with no data
				if ($rawBody === '') {
					if ($httpCode >= 200 && $httpCode < 300) {
						return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => []];
					}
					
					return ['request' => ['result' => 0, 'errorId' => $httpCode, 'errorMessage' => 'HTTP error ' . $httpCode . ' with empty body']];
				}
				
				$data = json_decode($rawBody, true);
				
				if (json_last_error() !== JSON_ERROR_NONE) {
					return ['request' => ['result' => 0, 'errorId' => 0, 'errorMessage' => 'Invalid JSON response (HTTP ' . $httpCode . '): ' . json_last_error_msg()]];
				}
				
				// XPay error responses carry an 'errors' array with 'code' and 'description' per entry
				if (!empty($data['errors'])) {
					$first = reset($data['errors']);
					$errorCode = $first['code'] ?? 0;
					$errorMsg  = $first['description'] ?? 'Unknown XPay error';
					return ['request' => ['result' => 0, 'errorId' => $errorCode, 'errorMessage' => $errorMsg]];
				}
				
				if ($httpCode < 200 || $httpCode >= 300) {
					return ['request' => ['result' => 0, 'errorId' => $httpCode, 'errorMessage' => 'HTTP error ' . $httpCode]];
				}
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $data];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Generates a UUID v4 for use as a Correlation-Id header.
		 * The Correlation-Id must be an RFC 4122 UUID v4.
		 * @return string UUID v4 string
		 */
		private function generateCorrelationId(): string {
			$data = random_bytes(16);
			$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
			$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant bits
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}
	}