<?php
	
	namespace Quellabs\Shipments\SendCloud;
	
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the SendCloud API v2.
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * @see https://docs.sendcloud.com/api/v2/
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class SendCloudGateway {
		
		private const string BASE_URL = 'https://panel.sendcloud.sc/api/v2';
		private const string SERVICE_POINT_URL = 'https://servicepoints.sendcloud.sc/api/v2';
		
		/** @var string|null Google Geocoding API key, read from config */
		private ?string $geocodingApiKey;
		
		/** @var HttpClientInterface Shared HTTP client instance for SendCloud API */
		private HttpClientInterface $client;
		
		/** @var HttpClientInterface HTTP client for Nominatim (requires User-Agent) */
		private HttpClientInterface $nominatimClient;
		
		/** @var HttpClientInterface HTTP client for Google Maps (no custom User-Agent) */
		private HttpClientInterface $googleMapsClient;
		
		/**
		 * SendCloudGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			// Store geocoding key
			$rawKey = $config['geocoding_api_key'] ?? null;
			$this->geocodingApiKey = (is_string($rawKey) && $rawKey !== '') ? $rawKey : null;
			
			// Client for SendCloud calls
			$this->client = HttpClient::create([
				'auth_basic' => [$config['public_key'], $config['secret_key']],
				'timeout'    => 10
			]);
			
			// Nominatim requires a descriptive User-Agent per OSM usage policy.
			$this->nominatimClient = HttpClient::create([
				'timeout' => 5,
				'headers' => [
					'User-Agent' => 'Quellabs-Shipments/1.0',
				],
			]);
			
			// Google Maps API authenticates via query param (key=). No custom User-Agent needed.
			$this->googleMapsClient = HttpClient::create([
				'timeout' => 5,
			]);
		}
		
		/**
		 * Creates a parcel.
		 * @see https://docs.sendcloud.com/api/v2/#create-a-parcel
		 * @param array<string, mixed> $payload
		 * @return GatewayResponse
		 */
		public function createParcel(array $payload): array {
			return $this->request('POST', '/parcels', ['json' => $payload]);
		}
		
		/**
		 * Retrieves a single parcel by ID.
		 * @see https://docs.sendcloud.com/api/v2/#get-a-specific-parcel
		 * @param string|int $parcelId
		 * @return GatewayResponse
		 */
		public function getParcel(string|int $parcelId): array {
			return $this->request('GET', "/parcels/{$parcelId}");
		}
		
		/**
		 * Cancels a parcel.
		 * @see https://docs.sendcloud.com/api/v2/#cancel-delete-a-parcel
		 * @param string|int $parcelId
		 * @return GatewayResponse
		 */
		public function cancelParcel(string|int $parcelId): array {
			return $this->request('POST', "/parcels/{$parcelId}/cancel", ['json' => []]);
		}
		
		/**
		 * Returns available shipping methods.
		 * When $fromCountry/$toCountry are provided, the list is filtered by route.
		 * @see https://docs.sendcloud.com/api/v2/#get-shipping-methods
		 * @param string|null $fromCountry ISO 3166-1 alpha-2 sender country
		 * @param string|null $toCountry ISO 3166-1 alpha-2 recipient country
		 * @return GatewayResponse
		 */
		public function getShippingMethods(?string $fromCountry = null, ?string $toCountry = null): array {
			$query = array_filter([
				'from_country' => $fromCountry,
				'to_country'   => $toCountry,
			]);
			
			return $this->request('GET', '/shipping_methods', ['query' => $query]);
		}
		
		/**
		 * Returns service points (pickup locations) within a geographic bounding box.
		 * @see https://docs.sendcloud.com/api/v2/#get-service-points
		 * @param string[] $carriers List of carrier names to filter by (e.g. ['postnl', 'dhl'])
		 * @param string $country ISO 3166-1 alpha-2
		 * @param float $neLat North-east bounding box latitude
		 * @param float $neLng North-east bounding box longitude
		 * @param float $swLat South-west bounding box latitude
		 * @param float $swLng South-west bounding box longitude
		 * @return GatewayResponse
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
			return $this->request('GET', '/service-points/', ['query' => $query], self::SERVICE_POINT_URL);
		}
		
		/**
		 * Retrieves the label PDF URL for one or more parcels.
		 * @see https://docs.sendcloud.com/api/v2/#get-a-pdf-label
		 * @param string|int|array<int, int> $parcelId Single ID or array of IDs for a merged label
		 * @return GatewayResponse
		 */
		public function getLabel(string|int|array $parcelId): array {
			if (is_array($parcelId)) {
				return $this->request('GET', '/labels', ['query' => ['label' => ['parcels' => $parcelId]]]);
			}
			
			return $this->request('GET', "/labels/{$parcelId}");
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
		 * @return GatewayResponse
		 */
		public function geocodeAddress(string $postalCode, string $country, ?string $city = null): array {
			if (!empty($this->geocodingApiKey)) {
				return $this->geocodeWithGoogle($postalCode, $country, $city);
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
		 * @return GatewayResponse
		 */
		private function geocodeWithGoogle(string $postalCode, string $country, ?string $city): array {
			try {
				// Build payload for maps.googleapis.com
				$components = array_filter([
					'postal_code' => $postalCode,
					'country'     => $country,
				]);
				
				// Google uses a free-form 'address' plus structured 'components' for best accuracy
				$address = implode(' ', array_filter([$postalCode, $city, $country]));
				
				// Call client
				$response = $this->googleMapsClient->request('GET', 'https://maps.googleapis.com/maps/api/geocode/json', [
					'query' => [
						'address'    => $address,
						'components' => implode('|', array_map(
							fn($k, $v) => "{$k}:{$v}",
							array_keys($components),
							$components
						)),
						'key'        => $this->geocodingApiKey,
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
		 * @return GatewayResponse
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
				$response = $this->nominatimClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
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
		 * Sends an HTTP request and returns a normalised response array.
		 *
		 * The $options array is passed directly to the Symfony HttpClient, so the
		 * caller is responsible for encoding: use ['query' => ...] for GET params
		 * and ['json' => ...] for POST bodies. This keeps the method agnostic about
		 * how the body or query string is represented.
		 *
		 * SendCloud returns 4xx with a JSON body containing 'error.code' and 'error.message'.
		 *
		 * @param string $method HTTP verb (e.g. 'GET', 'POST')
		 * @param string $endpoint Path relative to the base URL (e.g. '/parcels/123')
		 * @param array<string, mixed> $options Symfony HttpClient request options
		 * @param string|null $baseUrl Override the default base URL (used for service points)
		 * @return GatewayResponse
		 */
		private function request(string $method, string $endpoint, array $options = [], ?string $baseUrl = null): array {
			try {
				// Create url
				$url = ($baseUrl ?? self::BASE_URL) . $endpoint;
				
				// Call API
				$response = $this->client->request($method, $url, $options);

				// Fetch status code
				$statusCode = $response->getStatusCode();
				
				// Decode the response body regardless of status so error bodies are available
				$decoded = json_decode($response->getContent(false), true);
				
				// Check if decoding worked
				if (json_last_error() !== JSON_ERROR_NONE) {
					return ['request' => ['result' => 0, 'errorId' => (string)json_last_error(), 'errorMessage' => json_last_error_msg()]];
				}
				
				// Treat a non-array body as an empty response (e.g. unexpected plain-text 2xx)
				$body = is_array($decoded) ? $decoded : [];
				
				// SendCloud signals errors via 4xx status codes with a structured error body
				if ($statusCode >= 400) {
					$error        = is_array($body['error'] ?? null) ? $body['error'] : [];
					$rawCode      = $error['code'] ?? null;
					$errorCode    = (is_string($rawCode) || is_int($rawCode)) ? (string)$rawCode : (string)$statusCode;
					$rawMessage   = $error['message'] ?? null;
					$errorMessage = is_string($rawMessage) ? $rawMessage : "HTTP {$statusCode}";
					return ['request' => ['result' => 0, 'errorId' => $errorCode, 'errorMessage' => $errorMessage]];
				}
				
				// Successful response: wrap the decoded body in the standard envelope.
				// body is array<mixed, mixed> from json_decode; cast to array<string, mixed> via
				// array_filter with no callback, which is safe here — SendCloud always returns
				// string keys at the top level. PHPStan needs the explicit annotation below.
				/** @var array<string, mixed> $body */
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $body];
			} catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				return ['request' => ['result' => 0, 'errorId' => (string)$e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
	}