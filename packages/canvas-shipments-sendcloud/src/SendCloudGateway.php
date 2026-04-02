<?php
	
	namespace Quellabs\Shipments\SendCloud;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	use Symfony\Contracts\HttpClient\ResponseInterface;
	
	/**
	 * Low-level wrapper around the SendCloud API v2.
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * @see https://docs.sendcloud.com/api/v2/
	 */
	class SendCloudGateway {
		
		private const BASE_URL = 'https://panel.sendcloud.sc/api/v2';
		private const SERVICE_POINT_URL = 'https://servicepoints.sendcloud.sc/api/v2';
		
		/** @var HttpClientInterface Shared HTTP client instance for SendCloud API */
		private HttpClientInterface $client;
		
		/** @var HttpClientInterface HTTP client for external geocoding requests */
		private HttpClientInterface $geocodingClient;
		
		/**
		 * SendCloudGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			$this->client = HttpClient::create([
				'auth_basic' => [$config['public_key'], $config['secret_key']],
				'timeout'    => 10,
				'headers'    => [
					'Sendcloud-Partner-Id' => $config['partner_id'] ?? '',
				],
			]);
			
			$this->geocodingClient = HttpClient::create([
				'timeout' => 5,
				'headers' => [
					'User-Agent' => 'Quellabs-Shipments/1.0',
				],
			]);
		}
		
		/**
		 * Creates a parcel.
		 * @see https://docs.sendcloud.com/api/v2/#create-a-parcel
		 * @param array $payload
		 * @return array
		 */
		public function createParcel(array $payload): array {
			return $this->post('/parcels', $payload);
		}
		
		/**
		 * Retrieves a single parcel by ID.
		 * @see https://docs.sendcloud.com/api/v2/#get-a-specific-parcel
		 * @param string|int $parcelId
		 * @return array
		 */
		public function getParcel(string|int $parcelId): array {
			return $this->get("/parcels/{$parcelId}");
		}
		
		/**
		 * Cancels a parcel.
		 * @see https://docs.sendcloud.com/api/v2/#cancel-delete-a-parcel
		 * @param string|int $parcelId
		 * @return array
		 */
		public function cancelParcel(string|int $parcelId): array {
			return $this->post("/parcels/{$parcelId}/cancel", []);
		}
		
		/**
		 * Returns available shipping methods.
		 * When $fromCountry/$toCountry are provided, the list is filtered by route.
		 * @see https://docs.sendcloud.com/api/v2/#get-shipping-methods
		 * @param string|null $fromCountry ISO 3166-1 alpha-2 sender country
		 * @param string|null $toCountry ISO 3166-1 alpha-2 recipient country
		 * @return array
		 */
		public function getShippingMethods(?string $fromCountry = null, ?string $toCountry = null): array {
			$query = array_filter([
				'from_country' => $fromCountry,
				'to_country'   => $toCountry,
			]);
			
			return $this->get('/shipping_methods', $query);
		}
		
		/**
		 * Returns service points (pickup locations) within a geographic bounding box.
		 * @see https://docs.sendcloud.com/api/v2/#get-service-points
		 * @param array $carriers List of carrier names to filter by (e.g. ['postnl', 'dhl'])
		 * @param string $country ISO 3166-1 alpha-2
		 * @param float $neLat North-east bounding box latitude
		 * @param float $neLng North-east bounding box longitude
		 * @param float $swLat South-west bounding box latitude
		 * @param float $swLng South-west bounding box longitude
		 * @return array
		 */
		public function getServicePoints(array $carriers, string $country, float $neLat, float $neLng, float $swLat, float $swLng): array {
			$query = [
				'carrier'      => implode(',', $carriers),
				'country'      => $country,
				'ne_latitude'  => $neLat,
				'ne_longitude' => $neLng,
				'sw_latitude'  => $swLat,
				'sw_longitude' => $swLng,
			];
			
			// Service points use a different base domain
			return $this->get('/service-points/', $query, self::SERVICE_POINT_URL);
		}
		
		/**
		 * Retrieves the label PDF URL for one or more parcels.
		 * @see https://docs.sendcloud.com/api/v2/#get-a-pdf-label
		 * @param string|int|array $parcelId Single ID or array of IDs for a merged label
		 * @return array
		 */
		public function getLabel(string|int|array $parcelId): array {
			if (is_array($parcelId)) {
				return $this->get('/labels', ['label' => ['parcels' => $parcelId]]);
			}
			
			return $this->get("/labels/{$parcelId}");
		}
		
		/**
		 * Verifies a SendCloud webhook signature.
		 * SendCloud signs the raw request body with HMAC-SHA256 using your webhook secret.
		 * The signature is passed in the Sendcloud-Signature header.
		 * @see https://docs.sendcloud.com/api/v2/#webhook-signature
		 * @param string $rawBody The raw (un-decoded) request body
		 * @param string $signature Value of the Sendcloud-Signature header
		 * @param string $webhookSecret The webhook secret from your SendCloud integration settings
		 * @return bool
		 */
		public function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): bool {
			if (empty($webhookSecret) || empty($signature)) {
				return false;
			}
			
			$expected = hash_hmac('sha256', $rawBody, $webhookSecret);
			return hash_equals($expected, $signature);
		}
		
		/**
		 * Geocodes an address to a lat/lng using either Google Maps or Nominatim,
		 * depending on whether a geocoding_api_key is configured.
		 * @param string $postalCode
		 * @param string $country ISO 3166-1 alpha-2
		 * @param string|null $city
		 * @param string|null $apiKey Google Geocoding API key, or null to use Nominatim
		 * @return array
		 */
		public function geocodeAddress(string $postalCode, string $country, ?string $city = null, ?string $apiKey = null): array {
			if (!empty($apiKey)) {
				return $this->geocodeWithGoogle($postalCode, $country, $city, $apiKey);
			} else {
				return $this->geocodeWithNominatim($postalCode, $country, $city);
			}
		}
		
		/**
		 * Geocodes using the Google Maps Geocoding API.
		 * @see https://developers.google.com/maps/documentation/geocoding
		 * @param string $postalCode
		 * @param string $country
		 * @param string|null $city
		 * @param string $apiKey
		 * @return array
		 */
		private function geocodeWithGoogle(string $postalCode, string $country, ?string $city, string $apiKey): array {
			try {
				// Build payload for maps.googleapis.com
				$components = array_filter([
					'postal_code' => $postalCode,
					'country'     => $country,
				]);
				
				// Google uses a free-form 'address' plus structured 'components' for best accuracy
				$address = implode(' ', array_filter([$postalCode, $city, $country]));
				
				// Call client
				$response = $this->geocodingClient->request('GET', 'https://maps.googleapis.com/maps/api/geocode/json', [
					'query' => [
						'address'    => $address,
						'components' => implode('|', array_map(
							fn($k, $v) => "{$k}:{$v}",
							array_keys($components),
							$components
						)),
						'key' => $apiKey,
					],
				]);
				
				// Transform result to array
				$body = $response->toArray(false);
				
				// If the call failed, return an error status
				if (($body['status'] ?? '') !== 'OK' || empty($body['results'][0]['geometry']['location'])) {
					$status = $body['status'] ?? 'UNKNOWN';
					return ['request' => ['result' => 0, 'errorId' => $status, 'errorMessage' => "Google Geocoding returned status {$status}"]];
				}
				
				// Fetch location data
				$location = $body['results'][0]['geometry']['location'];
				
				// Return location data
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [
					'lat' => (float)$location['lat'],
					'lng' => (float)$location['lng'],
				]];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Geocodes using Nominatim (OpenStreetMap). Free, no key required.
		 * @see https://nominatim.org/release-docs/latest/api/Search/
		 * @param string $postalCode
		 * @param string $country
		 * @param string|null $city
		 * @return array
		 */
		private function geocodeWithNominatim(string $postalCode, string $country, ?string $city): array {
			try {
				// Build payload for nominatim.openstreetmap.org
				$query = array_filter([
					'postalcode' => $postalCode,
					'country'    => $country,
					'city'       => $city,
					'format'     => 'json',
					'limit'      => 1,
				], fn($v) => $v !== null && $v !== '');
				
				// Call client
				$response = $this->geocodingClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
					'query' => $query,
				]);
				
				// Transform result to array
				$results = $response->toArray(false);
				
				// Validate location data
				if (empty($results[0]['lat']) || empty($results[0]['lon'])) {
					return ['request' => ['result' => 0, 'errorId' => 'no_results', 'errorMessage' => 'Nominatim returned no results for the given address']];
				}
				
				// Return location data
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [
					'lat' => (float)$results[0]['lat'],
					'lng' => (float)$results[0]['lon'],
				]];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Sends a GET request and returns a normalised response array.
		 * @param string $endpoint Path relative to the base URL (e.g. '/parcels/123')
		 * @param array $query Optional query string parameters
		 * @param string|null $baseUrl Override the default base URL (used for service points)
		 * @return array
		 */
		private function get(string $endpoint, array $query = [], ?string $baseUrl = null): array {
			try {
				$url = ($baseUrl ?? self::BASE_URL) . $endpoint;
				
				$response = $this->client->request('GET', $url, [
					'query' => $query,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Sends a POST request and returns a normalized response array.
		 * @param string $endpoint Path relative to the base URL
		 * @param array $payload JSON request body
		 * @return array
		 */
		private function post(string $endpoint, array $payload): array {
			try {
				$response = $this->client->request('POST', self::BASE_URL . $endpoint, [
					'json' => $payload,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Normalizes an HTTP response into the shared result envelope.
		 * SendCloud returns 4xx with a JSON body containing 'error.code' and 'error.message'.
		 * @param ResponseInterface $response
		 * @return array
		 */
		private function normaliseResponse(ResponseInterface $response): array {
			$statusCode = $response->getStatusCode();
			$body = json_decode($response->getContent(false), true);
			
			if ($statusCode >= 400) {
				$errorCode = $body['error']['code'] ?? $statusCode;
				$errorMessage = $body['error']['message'] ?? "HTTP {$statusCode}";
				return ['request' => ['result' => 0, 'errorId' => $errorCode, 'errorMessage' => $errorMessage]];
			}
			
			return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $body];
		}
	}