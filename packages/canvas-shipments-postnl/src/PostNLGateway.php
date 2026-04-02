<?php
	
	namespace Quellabs\Shipments\PostNL;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the PostNL API.
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 *
	 * Authentication:
	 *   PostNL uses a single API key sent as: apikey: <key>
	 *   Obtain your key from the PostNL Developer Portal.
	 *
	 * Base URLs:
	 *   Live: https://api.postnl.nl
	 *   Sandbox: https://api-sandbox.postnl.nl
	 *
	 * Response envelope:
	 *   Success: varies per endpoint (ResponseShipments, Shipment, GetLocationsResult, etc.)
	 *   Error:   { "fault": { "faultstring": "...", "detail": { "errorcode": "..." } } }
	 *          or legacy: { "Errors": [ { "Code": "...", "Description": "..." } ] }
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * @see https://developer.postnl.nl/docs/
	 */
	class PostNLGateway {
		
		/** PostNL live API base URL */
		private const BASE_URL_LIVE = 'https://api.postnl.nl';
		
		/** PostNL sandbox API base URL */
		private const BASE_URL_SANDBOX = 'https://api-sandbox.postnl.nl';
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/** @var string Resolved base URL (live or sandbox) */
		private string $baseUrl;
		
		/**
		 * PostNLGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			if ($config['test_mode']) {
				$this->baseUrl = self::BASE_URL_SANDBOX;
			} else {
				$this->baseUrl = self::BASE_URL_LIVE;
			}
			
			if ($config['test_mode']) {
				$apiKey = $config['api_key_test'];
			} else {
				$apiKey = $config['api_key'];
			}
			
			$this->client = HttpClient::create([
				'timeout' => 10,
				'headers' => [
					'apikey'       => $apiKey,
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				],
			]);
		}
		
		/**
		 * Creates one or more shipments and returns the barcode and label inline.
		 *
		 * The payload must follow the PostNL Shipment API envelope:
		 *   { "Customer": {...}, "Message": {...}, "Shipments": [ {...} ] }
		 *
		 * Returns: { "ResponseShipments": [ { "Barcode": "...", "Labels": [ { "Content": "<base64>" } ] } ] }
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/send-track/shipment
		 * @param array $payload Full PostNL shipment envelope
		 * @return array
		 */
		public function createShipment(array $payload): array {
			return $this->post('/v1/shipment', $payload, ['confirm' => 'true']);
		}
		
		/**
		 * Deletes (cancels) a shipment by barcode.
		 *
		 * Only possible before the parcel has been scanned by the carrier.
		 * Returns HTTP 200 on success, 409 if already in transit.
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/send-track/shipment/delete-shipment
		 * @param string $barcode PostNL barcode from ShipmentResult::$parcelId
		 * @return array
		 */
		public function deleteShipment(string $barcode): array {
			return $this->delete("/v1/shipment/{$barcode}");
		}
		
		/**
		 * Retrieves the current status of a shipment by barcode.
		 *
		 * Returns: { "Shipment": { "Barcode": "...", "Events": [ {...} ], ... } }
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/status/status-by-barcode
		 * @param string $barcode PostNL carrier barcode
		 * @return array
		 */
		public function getStatus(string $barcode): array {
			return $this->get("/v2/status/barcode/{$barcode}");
		}
		
		/**
		 * Retrieves the label PDF for an existing shipment by barcode.
		 *
		 * Used when a label was not requested at creation time, or needs to be reprinted.
		 * The label is returned as base64-encoded PDF content in Labels[0]['Content'].
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/send-track/confirming
		 * @param string $barcode PostNL barcode
		 * @return array
		 */
		public function getLabel(string $barcode): array {
			return $this->get("/v1/confirming/label/{$barcode}");
		}
		
		/**
		 * Returns available delivery timeframes for a given recipient address and date window.
		 *
		 * The Timeframe API is the correct tool for populating a checkout delivery option picker.
		 * It returns all available windows (Daytime, Morning, Evening, Sunday, etc.) per day over
		 * the requested range, including the From/To times for each slot.
		 *
		 * The Options parameter controls which delivery types are included in the response.
		 * Pass all types your account has enabled; the API filters to what is actually available
		 * at the recipient address.
		 *
		 * Returns:
		 *   {
		 *     "Timeframes": {
		 *       "Timeframe": [
		 *         {
		 *           "Date": "dd-mm-yyyy",
		 *           "Timeframes": {
		 *             "TimeframeTimeFrame": [
		 *               { "From": "08:00:00", "To": "12:00:00", "Options": { "string": "Morning" } },
		 *               { "From": "11:30:00", "To": "14:00:00", "Options": { "string": "Daytime" } },
		 *               { "From": "17:30:00", "To": "21:30:00", "Options": { "string": "Evening" } }
		 *             ]
		 *           }
		 *         },
		 *         ...
		 *       ]
		 *     }
		 *   }
		 *
		 * @see https://developer.postnl.nl/browse-apis/delivery-options/timeframe-webservice/
		 * @param string $postalCode Recipient postal code
		 * @param string $houseNumber House number
		 * @param string $countryCode ISO 3166-1 alpha-2 country code
		 * @param string $startDate Start of the window (dd-mm-yyyy); typically tomorrow
		 * @param string $endDate End of the window (dd-mm-yyyy); max ~2 weeks ahead
		 * @param array $options Delivery types to request, e.g. ['Daytime', 'Morning', 'Evening', 'Sunday']
		 * @return array
		 */
		public function getTimeframes(
			string $postalCode,
			string $houseNumber,
			string $countryCode,
			string $startDate,
			string $endDate,
			array  $options = ['Daytime', 'Morning', 'Evening']
		): array {
			$query = [
				'StartDate'          => $startDate,
				'EndDate'            => $endDate,
				'PostalCode'         => $postalCode,
				'HouseNumber'        => $houseNumber,
				'CountryCode'        => $countryCode,
				'AllowSundaySorting' => in_array('Sunday', $options, true) ? 'true' : 'false',
				'Options'            => implode(',', $options),
			];
			
			return $this->get('/v2_1/calculate/timeframes', $query);
		}
		
		/**
		 * Returns PostNL service points near a given address.
		 *
		 * 'PG' (Pakket Gemak) is the standard pick-up-at-PostNL-location delivery option.
		 * The Location API supports up to 20 results; the Checkout API is limited to 3.
		 *
		 * Returns: { "GetLocationsResult": { "ResponseLocation": [ { "LocationCode": ..., ... } ] } }
		 *
		 * @see https://developer.postnl.nl/browse-apis/delivery-options/location-webservice/
		 * @param string $postalCode Search origin postal code
		 * @param string $houseNumber House number for proximity sorting
		 * @param string $countryCode ISO 3166-1 alpha-2 country code
		 * @param int $maxResults Maximum number of locations to return (1–20, default 10)
		 * @return array
		 */
		public function getNearestLocations(
			string $postalCode,
			string $houseNumber,
			string $countryCode,
			int    $maxResults = 10
		): array {
			$query = [
				'CountryCode'     => $countryCode,
				'PostalCode'      => $postalCode,
				'HouseNumber'     => $houseNumber,
				'DeliveryOptions' => 'PG',
				'MaxLocations'    => min($maxResults, 20),
			];
			
			return $this->get('/v2_1/locations/nearest', $query);
		}
		
		/**
		 * Sends a GET request and returns a normalised response array.
		 * @param string $endpoint Path relative to the base URL
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
		 * @param array $query Optional query string parameters
		 * @return array
		 */
		private function post(string $endpoint, array $payload, array $query = []): array {
			try {
				$response = $this->client->request('POST', $this->baseUrl . $endpoint, [
					'query' => $query,
					'json'  => $payload,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Sends a DELETE request and returns a normalised response array.
		 * @param string $endpoint Path relative to the base URL
		 * @return array
		 */
		private function delete(string $endpoint): array {
			try {
				$response = $this->client->request('DELETE', $this->baseUrl . $endpoint);
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Normalises an HTTP response into the shared result envelope.
		 *
		 * PostNL error responses come in two formats depending on the endpoint version:
		 *
		 * Modern (v2+):
		 *   { "fault": { "faultstring": "...", "detail": { "errorcode": "..." } } }
		 *
		 * Legacy (v1):
		 *   { "Errors": [ { "Code": "...", "Description": "..." } ] }
		 *
		 * @param \Symfony\Contracts\HttpClient\ResponseInterface $response
		 * @return array
		 */
		private function normaliseResponse(\Symfony\Contracts\HttpClient\ResponseInterface $response): array {
			$statusCode = $response->getStatusCode();
			$body = json_decode($response->getContent(false), true);
			
			if ($statusCode >= 400) {
				// Modern fault envelope
				if (isset($body['fault'])) {
					$errorMessage = $body['fault']['faultstring'] ?? "HTTP {$statusCode}";
					$errorId = $body['fault']['detail']['errorcode'] ?? $statusCode;
					
					return [
						'request' => [
							'result'       => 0,
							'errorId'      => $errorId,
							'errorMessage' => $errorMessage,
						],
					];
				}
				
				// Legacy Errors array
				if (!empty($body['Errors'])) {
					$first = $body['Errors'][0];
					$errorMessage = $first['Description'] ?? "HTTP {$statusCode}";
					$errorId = $first['Code'] ?? $statusCode;
					
					return [
						'request' => [
							'result'       => 0,
							'errorId'      => $errorId,
							'errorMessage' => $errorMessage,
						],
					];
				}
				
				return [
					'request' => [
						'result'       => 0,
						'errorId'      => $statusCode,
						'errorMessage' => "HTTP {$statusCode}",
					],
				];
			}
			
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => $body ?? [],
			];
		}
	}
