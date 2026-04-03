<?php
	
	namespace Quellabs\Shipments\MyParcel;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the MyParcel API v1.1.
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 *
	 * Authentication:
	 *   MyParcel uses a single API key, base64-encoded and sent as: Authorization: basic <base64(key)>
	 *   Note: MyParcel documents this as "basic" but it is NOT HTTP Basic Auth —
	 *   it is the literal header value, so we set it manually rather than using auth_basic.
	 *
	 * Response envelope:
	 *   Success: { "data": { "<resource>": [...] } }
	 *   Error:   { "message": "...", "errors": [...] }
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * @see https://developer.myparcel.nl/api-reference/
	 */
	class MyParcelGateway {
		
		/** MyParcel NL base URL */
		private const BASE_URL_NL = 'https://api.myparcel.nl';
		
		/** MyParcel BE (sendmyparcel.be) base URL */
		private const BASE_URL_BE = 'https://api.sendmyparcel.be';
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/** @var string Resolved base URL (NL or BE) */
		private string $baseUrl;
		
		/**
		 * MyParcelGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			// Select the correct endpoint based on the configured region
			$this->baseUrl = ($config['region'] === 'be') ? self::BASE_URL_BE : self::BASE_URL_NL;
			
			// Resolve which API key to use
			if ($config['test_mode']) {
				$apiKey = $config['api_key_test'] ?: $config['api_key'];
			} else {
				$apiKey = $config['api_key'];
			}
			
			// MyParcel authentication: single key, base64-encoded, sent as a custom header.
			// This is NOT standard HTTP Basic Auth — we set the Authorization header directly.
			$this->client = HttpClient::create([
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'basic ' . base64_encode($apiKey),
					'Accept'        => 'application/json',
				],
			]);
		}
		
		/**
		 * Creates one or more shipments.
		 *
		 * The payload must be wrapped in the MyParcel envelope:
		 *   { "data": { "shipments": [ {...}, ... ] } }
		 *
		 * Returns: { "data": { "ids": [ { "id": 123, "reference_identifier": "..." } ] } }
		 *
		 * @see https://developer.myparcel.nl/api-reference/02.shipments.html#post-shipments
		 * @param array $payload The full envelope (caller must wrap in data.shipments)
		 * @return array
		 */
		public function createParcel(array $payload): array {
			return $this->post('/shipments', $payload, 'application/vnd.shipment+json;version=1.1;charset=utf-8');
		}
		
		/**
		 * Retrieves shipment details by internal shipment ID.
		 *
		 * Returns: { "data": { "shipments": [ {...} ] } }
		 * The returned shipment contains the barcode once assigned by the carrier.
		 *
		 * @see https://developer.myparcel.nl/api-reference/02.shipments.html#get-shipments
		 * @param string|int $shipmentId Internal MyParcel shipment ID
		 * @return array
		 */
		public function getShipment(string|int $shipmentId): array {
			return $this->get("/shipments/{$shipmentId}");
		}
		
		/**
		 * Retrieves the label PDF for one or more shipments.
		 *
		 * MyParcel requires a separate call to obtain the label after creation.
		 * For multiple IDs, pass them semicolon-separated: "123;456;789"
		 *
		 * Returns: { "data": { "pdfs": { "url": "https://..." } } }
		 *
		 * @see https://developer.myparcel.nl/api-reference/08.labels.html
		 * @param string|int|array $shipmentId Single ID, semicolon-string, or array of IDs
		 * @return array
		 */
		public function getLabel(string|int|array $shipmentId): array {
			if (is_array($shipmentId)) {
				$shipmentId = implode(';', array_filter($shipmentId, fn($id) => (int)$id > 0));
			}
			
			return $this->get("/shipment_labels/{$shipmentId}");
		}
		
		/**
		 * Returns delivery options (timeframes and pickup points) for a given address.
		 * Options are computed per recipient address, postal code, and carrier.
		 *
		 * Returns: { "data": { "delivery": [...], "pickup": [...] } }
		 *
		 * @see https://developer.myparcel.nl/api-reference/04.delivery-options.html
		 * @param int $carrierId MyParcel carrier ID (e.g. 1 for PostNL)
		 * @param string $postalCode Recipient postal code
		 * @param string $houseNumber House number
		 * @param string $city City name
		 * @param string $countryCode ISO 3166-1 alpha-2
		 * @param array $extraOptions Additional query params (cutoff_time, dropoff_delay, etc.)
		 * @return array
		 */
		public function getDeliveryOptions(
			int    $carrierId,
			string $postalCode,
			string $houseNumber,
			string $city,
			string $countryCode,
			array  $extraOptions = []
		): array {
			$query = array_merge($extraOptions, [
				'carrier'     => $carrierId,
				'cc'          => $countryCode,
				'postal_code' => $postalCode,
				'number'      => $houseNumber,
				'city'        => $city,
			]);
			
			return $this->get('/delivery_options', $query);
		}
		
		/**
		 * Returns track-trace information for one or more barcodes.
		 *
		 * Note: this endpoint requires the carrier barcode, not the MyParcel shipment ID.
		 * Use getShipment() if you only have the internal ID.
		 *
		 * Returns: { "data": { "tracktraces": [ {...} ] } }
		 *
		 * @see https://developer.myparcel.nl/api-reference/06.track-trace.html
		 * @param string|array $barcode Single barcode or array of barcodes
		 * @return array
		 */
		public function getTrackTrace(string|array $barcode): array {
			if (is_array($barcode)) {
				$barcode = implode(';', $barcode);
			}
			
			return $this->get("/tracktraces/{$barcode}");
		}
		
		/**
		 * Sends a GET request and returns a normalised response array.
		 * @param string $endpoint Path relative to the base URL (e.g. '/shipments/123')
		 * @param array $query Optional query string parameters
		 * @return array
		 */
		private function get(string $endpoint, array $query = []): array {
			try {
				$response = $this->client->request('GET', $this->baseUrl . $endpoint, [
					'query' => $query,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Sends a POST request and returns a normalised response array.
		 * @param string $endpoint Path relative to the base URL
		 * @param array $payload JSON request body
		 * @param string $contentType Content-Type header override (MyParcel uses versioned vnd types)
		 * @return array
		 */
		private function post(string $endpoint, array $payload, string $contentType = 'application/json'): array {
			try {
				$response = $this->client->request('POST', $this->baseUrl . $endpoint, [
					'headers' => ['Content-Type' => $contentType],
					'json'    => $payload,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Normalises an HTTP response into the shared result envelope.
		 *
		 * MyParcel error responses:
		 *   HTTP 4xx/5xx with JSON body: { "message": "...", "errors": { "field": ["msg"] } }
		 *   The top-level "message" is the most useful single-string summary.
		 *
		 * @param \Symfony\Contracts\HttpClient\ResponseInterface $response
		 * @return array
		 */
		private function normaliseResponse(\Symfony\Contracts\HttpClient\ResponseInterface $response): array {
			$statusCode = $response->getStatusCode();
			
			// getContent(false) suppresses exceptions on 4xx/5xx so we can read the body
			$body = json_decode($response->getContent(false), true);
			
			if ($statusCode >= 400) {
				// MyParcel puts a human-readable summary in "message" and field-level detail in "errors"
				$errorMessage = $body['message'] ?? "HTTP {$statusCode}";
				
				// Append the first field error if present for extra context
				if (!empty($body['errors'])) {
					$firstField = array_key_first($body['errors']);
					$firstMessage = $body['errors'][$firstField][0] ?? null;
					
					if ($firstMessage !== null) {
						$errorMessage .= " ({$firstField}: {$firstMessage})";
					}
				}
				
				return [
					'request' => [
						'result'       => 0,
						'errorId'      => $statusCode,
						'errorMessage' => $errorMessage,
					],
				];
			}
			
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => $body,
			];
		}
	}
