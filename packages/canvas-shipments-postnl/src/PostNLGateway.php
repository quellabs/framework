<?php
	
	namespace Quellabs\Shipments\PostNL;
	
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class PostNLGateway {
		
		/** PostNL live API base URL */
		private const string BASE_URL_LIVE = 'https://api.postnl.nl';
		
		/** PostNL sandbox API base URL */
		private const string BASE_URL_SANDBOX = 'https://api-sandbox.postnl.nl';
		
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
		 * @param array<string, mixed> $payload Full PostNL shipment envelope
		 * @return GatewayResponse
		 */
		public function createShipment(array $payload): array {
			return $this->request('POST', '/v1/shipment', $payload, ['confirm' => 'true']);
		}
		
		/**
		 * Deletes (cancels) a shipment by barcode.
		 *
		 * Only possible before the parcel has been scanned by the carrier.
		 * Returns HTTP 200 on success, 409 if already in transit.
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/send-track/shipment/delete-shipment
		 * @param string $barcode PostNL barcode from ShipmentResult::$parcelId
		 * @return GatewayResponse
		 */
		public function deleteShipment(string $barcode): array {
			return $this->request('DELETE', "/v1/shipment/{$barcode}");
		}
		
		/**
		 * Retrieves the current status of a shipment by barcode.
		 *
		 * Returns: { "Shipment": { "Barcode": "...", "Events": [ {...} ], ... } }
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/status/status-by-barcode
		 * @param string $barcode PostNL carrier barcode
		 * @return GatewayResponse
		 */
		public function getStatus(string $barcode): array {
			return $this->request('GET', "/v2/status/barcode/{$barcode}");
		}
		
		/**
		 * Retrieves the label PDF for an existing shipment by barcode.
		 *
		 * Used when a label was not requested at creation time, or needs to be reprinted.
		 * The label is returned as base64-encoded PDF content in Labels[0]['Content'].
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/send-track/confirming
		 * @param string $barcode PostNL barcode
		 * @return GatewayResponse
		 */
		public function getLabel(string $barcode): array {
			return $this->request('GET', "/v1/confirming/label/{$barcode}");
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
		 * @param array<int, string> $options Delivery types to request, e.g. ['Daytime', 'Morning', 'Evening', 'Sunday']
		 * @return GatewayResponse
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
			
			return $this->request('GET', '/v2_1/calculate/timeframes', [], $query);
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
		 * @return GatewayResponse
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
			
			return $this->request('GET', '/v2_1/locations/nearest', [], $query);
		}
		
		/**
		 * Sends an HTTP request, normalises the response, and returns a GatewayResponse envelope.
		 * @param string $method HTTP method ('GET', 'POST', 'DELETE', etc.)
		 * @param string $endpoint Path relative to the base URL (e.g. '/v1/shipment')
		 * @param array<string, mixed> $payload Request body; serialised as JSON for POST/PUT/PATCH; ignored for GET and DELETE
		 * @param array<string, mixed> $query Optional query string parameters appended to the URL
		 * @return GatewayResponse
		 */
		private function request(string $method, string $endpoint, array $payload = [], array $query = []): array {
			try {
				// Build the Symfony HttpClient options array. The 'query' key is always included
				// so the client appends any provided parameters to the URL. The 'json' key is only
				// set when a non-empty payload is given, which also sets Content-Type automatically;
				// omitting it for GET/DELETE avoids sending an empty body.
				$options = ['query' => $query];
				
				if (!empty($payload)) {
					$options['json'] = $payload;
				}
				
				// Call API
				$response = $this->client->request($method, $this->baseUrl . $endpoint, $options);
				
				// Fetch status code
				$statusCode = $response->getStatusCode();
				
				// Decode with $throw = false so HTTP 4xx/5xx responses do not raise an exception;
				// we inspect the status code ourselves to produce a normalised error envelope.
				$body = json_decode($response->getContent(false), true);
				
				// Check if decoding worked
				if (json_last_error() !== JSON_ERROR_NONE) {
					return ['request' => ['result' => 0, 'errorId' => (string)json_last_error(), 'errorMessage' => json_last_error_msg()]];
				}

				// StatusCode signals error
				if ($statusCode >= 400) {
					// Modern fault envelope
					if (isset($body['fault'])) {
						$errorMessage = $body['fault']['faultstring'] ?? "HTTP {$statusCode}";
						$errorId = $body['fault']['detail']['errorcode'] ?? $statusCode;
						return ['request' => ['result' => 0, 'errorId' => $errorId, 'errorMessage' => $errorMessage]];
					}
					
					// Legacy Errors array
					if (!empty($body['Errors'])) {
						$first = $body['Errors'][0];
						$errorMessage = $first['Description'] ?? "HTTP {$statusCode}";
						$errorId = $first['Code'] ?? $statusCode;
						return ['request' => ['result' => 0, 'errorId' => $errorId, 'errorMessage' => $errorMessage]];
					}
					
					// Unknown error format: fall back to the raw HTTP status code
					return ['request' => ['result' => 0, 'errorId' => (string)$statusCode, 'errorMessage' => "HTTP {$statusCode}"]];
				}
				
				// Successful response: wrap the decoded body in the standard envelope.
				// $body is coalesced to an empty array in case the endpoint returns no content (e.g. 204).
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $body ?? []];
			} catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				// Transport-level failures (DNS, timeout, TLS, connection refused, interrupted
				// response stream) are caught here and mapped to the same error envelope so
				// callers never need to handle exceptions. Programming errors (\Error, \TypeError,
				// etc.) are intentionally not caught and will propagate normally.
				return ['request' => ['result' => 0, 'errorId' => (string)$e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
	}