<?php
	
	namespace Quellabs\Shipments\DHL;
	
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the DHL Parcel NL API (api-gw.dhlparcel.nl).
	 * Handles raw HTTP communication, JWT authentication with token refresh,
	 * and response normalisation.
	 *
	 * Authentication:
	 *   DHL uses JWT. Credentials (userId + key) are exchanged for a short-lived
	 *   accessToken (~15 minutes) and a longer-lived refreshToken (~7 days).
	 *   This gateway handles token refresh transparently — a fresh token is obtained
	 *   before the first request, and re-fetched via the refresh token when expired.
	 *
	 *   Tokens are held in-memory only (per-request lifecycle). For long-running
	 *   processes or queue workers, persist and reload the token pair via the
	 *   'token_cache' config entry (see getDefaults()).
	 *
	 * Response envelope:
	 *   DHL does not use a unified envelope. Successful responses are bare objects or
	 *   arrays. Error responses are HTTP 4xx/5xx with a JSON body:
	 *     { "title": "...", "detail": "...", "status": 422 }
	 *   or plain strings for some 4xx errors.
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => <data>]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * @see https://api-gw.dhlparcel.nl/docs/
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class DHLGateway {
		
		/** DHL Parcel NL production base URL */
		public const string BASE_URL_LIVE = 'https://api-gw.dhlparcel.nl';
		
		/** DHL Parcel NL acceptance (test) base URL */
		public const string BASE_URL_TEST = 'https://api-gw-accept.dhlparcel.nl';
		
		/** @var HttpClientInterface Shared HTTP client (no Authorization header — added per-request) */
		private HttpClientInterface $client;
		
		/** @var string Resolved base URL — production or acceptance depending on test_mode */
		private string $baseUrl;
		
		/** @var string Authentication endpoint (derived from resolved base URL) */
		private string $authUrl;
		
		/** @var string Token refresh endpoint (derived from resolved base URL) */
		private string $refreshUrl;
		
		/** @var string|null Current access token (JWT) */
		private ?string $accessToken = null;
		
		/** @var int Unix timestamp at which the access token expires */
		private int $accessTokenExpiration = 0;
		
		/** @var string|null Refresh token for obtaining a new access token without re-authenticating */
		private ?string $refreshToken = null;
		
		/** @var int Unix timestamp at which the refresh token expires */
		private int $refreshTokenExpiration = 0;
		
		/** @var string userId credential from config */
		private string $userId;
		
		/** @var string API key credential from config */
		private string $apiKey;
		
		/**
		 * DHLGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			$isTest = (bool)($config['test_mode'] ?? false);
			
			// Select base URL and credentials based on test_mode.
			// Falls back to live credentials when test credentials are not configured.
			if ($isTest) {
				$this->baseUrl = self::BASE_URL_TEST;
				$this->userId = $config['user_id_test'] ?: $config['user_id'];
				$this->apiKey = $config['api_key_test'] ?: $config['api_key'];
			} else {
				$this->baseUrl = self::BASE_URL_LIVE;
				$this->userId = $config['user_id'];
				$this->apiKey = $config['api_key'];
			}
			
			// Auth endpoints are always relative to the resolved base URL
			$this->authUrl = $this->baseUrl . '/authenticate/api-key';
			$this->refreshUrl = $this->baseUrl . '/authenticate/refresh-token';
			
			// Create the httpclient
			$this->client = HttpClient::create(['timeout' => 15]);
		}
		
		/**
		 * Creates a shipment and returns the trackerCode (barcode) and internal shipment data.
		 * @see https://api-gw.dhlparcel.nl/docs/#/Shipments/post_shipments
		 * @param array<string, mixed> $payload Full shipment payload (shipmentId, receiver, shipper, options, pieces)
		 * @return GatewayResponse
		 */
		public function createShipment(array $payload): array {
			return $this->post('/shipments', $payload);
		}
		
		/**
		 * Retrieves the internal label ID for a given tracker code (barcode).
		 * This is a prerequisite for fetching the label PDF — DHL requires the internal ID,
		 * not the barcode, on the /labels/{id} endpoint.
		 *
		 * Returns an array of label objects; typically one per tracker code.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/#/Labels/get_labels
		 * @param string $trackerCode Barcode returned at shipment creation
		 * @return array<string, mixed>
		 */
		public function getLabelId(string $trackerCode): array {
			return $this->get('/labels', ['trackerCodeFilter' => $trackerCode]);
		}
		
		/**
		 * Retrieves track-and-trace events for a given tracker code.
		 *
		 * DHL returns an array of event objects ordered chronologically.
		 * The tracker key format is: "{barcode}+{postalCode}" when the postal code
		 * is known, or just the barcode for a coarser result.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/guide/chapters/05-track-and-trace.html
		 * @param string $trackerCode Barcode, optionally with postal code appended as "+{postalCode}"
		 * @return GatewayResponse
		 */
		public function getTrackTrace(string $trackerCode): array {
			return $this->request('GET', $this->baseUrl . '/track-trace', [
				'headers' => ['Accept' => 'application/json'],
				'query'   => ['key' => $trackerCode],
			]);
		}
		
		/**
		 * Finds nearby DHL ServicePoints for a given country and address.
		 * No authentication is required for this endpoint.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/guide/chapters/03-find-parcel-shop-location.html
		 * @param string $countryCode ISO 3166-1 alpha-2
		 * @param string|null $postalCode Postal code of the search origin
		 * @param string|null $city City of the search origin
		 * @param int $limit Maximum number of results (default 10)
		 * @return GatewayResponse
		 */
		public function getParcelShops(string $countryCode, ?string $postalCode = null, ?string $city = null, int $limit = 10): array {
			$query = array_filter([
				'zipCode' => $postalCode,
				'city'    => $city,
				'limit'   => $limit,
			], fn($v) => $v !== null && $v !== '');
			
			// Parcel-shop lookup does not require authentication
			return $this->request('GET', $this->baseUrl . "/parcel-shop-locations/{$countryCode}", [
				'headers' => ['Accept' => 'application/json'],
				'query'   => $query,
			]);
		}
		
		/**
		 * Sends an authenticated GET request.
		 *
		 * Obtains a valid token, injects it as a Bearer header, then delegates to
		 * request() for the actual HTTP call, error handling, and normalisation.
		 *
		 * Note: getValidToken() may throw \RuntimeException if authentication fails
		 * entirely. That exception propagates to the caller; it is not caught here,
		 * because a token failure is not a normal gateway error envelope situation.
		 *
		 * @param string $endpoint Path relative to the resolved base URL
		 * @param array<string, mixed> $query Optional query parameters
		 * @return GatewayResponse
		 */
		private function get(string $endpoint, array $query = []): array {
			try {
				$token = $this->getValidToken();
			} catch (\RuntimeException $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
			
			return $this->request('GET', $this->baseUrl . $endpoint, [
				'headers' => [
					'Authorization' => "Bearer {$token}",
					'Accept'        => 'application/json',
				],
				'query'   => $query,
			]);
		}
		
		/**
		 * Sends an authenticated POST request.
		 *
		 * Obtains a valid token, injects it as a Bearer header, then delegates to
		 * request() for the actual HTTP call, error handling, and normalisation.
		 *
		 * Note: getValidToken() may throw \RuntimeException if authentication fails
		 * entirely. That exception propagates to the caller; it is not caught here,
		 * because a token failure is not a normal gateway error envelope situation.
		 *
		 * @param string $endpoint Path relative to the resolved base URL
		 * @param array<string, mixed> $payload JSON request body
		 * @return GatewayResponse
		 */
		private function post(string $endpoint, array $payload): array {
			// Fetch auth token
			try {
				$token = $this->getValidToken();
			} catch (\RuntimeException $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
			
			// Call API with token attached
			return $this->request('POST', $this->baseUrl . $endpoint, [
				'headers' => [
					'Authorization' => "Bearer {$token}",
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'json'    => $payload,
			]);
		}
		
		/**
		 * Executes a raw HTTP request and returns a normalised gateway response.
		 *
		 * This is the single chokepoint for all outbound HTTP calls. Every method that
		 * needs to talk to the DHL API — authenticated or not — ends up here. Keeping
		 * the try/catch and response normalisation in one place means callers only
		 * need to describe *what* they want to send, not *how* to handle the result.
		 *
		 * DHL error response shape:
		 *   HTTP 4xx/5xx with JSON: { "title": "...", "detail": "...", "status": 422 }
		 *   The "detail" field is the most descriptive; "title" is the fallback.
		 *
		 * @param string $method HTTP method ('GET', 'POST', …)
		 * @param string $url Fully-qualified URL (base URL + path already resolved by the caller)
		 * @param array<string, mixed> $options Symfony HttpClient options (headers, query, json, …)
		 * @return GatewayResponse
		 */
		private function request(string $method, string $url, array $options = []): array {
			try {
				// Call the client
				$response = $this->client->request($method, $url, $options);
				
				// Fetch status code
				$statusCode = $response->getStatusCode();
				
				// getContent(false) suppresses exceptions on 4xx/5xx so we can read the body
				$rawBody = $response->getContent(false);
				
				// Decode the body
				$body = json_decode($rawBody, true);
				
				// Check if that worked, If not return error
				if (json_last_error() !== JSON_ERROR_NONE) {
					return ['request' => ['result' => 0, 'errorId' => (string)json_last_error(), 'errorMessage' => json_last_error_msg()]];
				}
				
				// If the status code is not in the success zone, return error
				if ($statusCode >= 400) {
					if (is_array($body)) {
						// Read message
						$message = $body['detail'] ?? $body['title'] ?? "HTTP {$statusCode}";
						
						// Append field-level validation errors when present
						if (!empty($body['errors']) && is_array($body['errors'])) {
							$firstError = reset($body['errors']);
							$message .= is_string($firstError) ? " ({$firstError})" : '';
						}
					} else {
						$message = $rawBody ?: "HTTP {$statusCode}";
					}
					
					return ['request' => ['result' => 0, 'errorId' => (string)$statusCode, 'errorMessage' => $message]];
				}
				
				// Return successful response
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $body];
			} catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				// Catches both transport-level errors (network, timeout) and any exception
				// thrown during response handling — converts them to the standard error envelope.
				return ['request' => ['result' => 0, 'errorId' => (string)$e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Returns a valid access token, refreshing or re-authenticating as needed.
		 *
		 * Strategy:
		 *   1. If we have a non-expired access token, return it immediately.
		 *   2. If we have a non-expired refresh token, use it to get a new access token.
		 *   3. Otherwise, authenticate from scratch with userId + apiKey.
		 *
		 * A 60-second safety margin is applied so tokens are refreshed before they
		 * actually expire, preventing races on slow networks.
		 *
		 * @return string|null
		 * @throws \RuntimeException when authentication fails
		 */
		private function getValidToken(): ?string {
			$now = time();
			$safetyMarginSeconds = 60;
			
			// Happy path: existing token still valid
			if ($this->accessToken !== null && $now < ($this->accessTokenExpiration - $safetyMarginSeconds)) {
				return $this->accessToken;
			}
			
			// Attempt refresh if the refresh token is still valid
			if ($this->refreshToken !== null && $now < ($this->refreshTokenExpiration - $safetyMarginSeconds)) {
				$this->refreshAccessToken();
				return $this->accessToken;
			}
			
			// Full re-authentication
			$this->authenticate();
			return $this->accessToken;
		}
		
		/**
		 * Authenticates with the DHL API using the stored userId and apiKey credentials.
		 * Stores the resulting tokens and their expiration timestamps.
		 * @throws \RuntimeException on authentication failure
		 */
		private function authenticate(): void {
			try {
				// Call API
				$response = $this->client->request('POST', $this->authUrl, [
					'headers' => [
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					'json'    => [
						'userId' => $this->userId,
						'key'    => $this->apiKey,
					],
				]);
				
				// Decode body
				$body = json_decode($response->getContent(false), true);
				
				// Error if invalid JSON
				if (json_last_error() !== JSON_ERROR_NONE) {
					throw new \RuntimeException("DHL refresh authentication failed: " . json_last_error_msg(), json_last_error());
				}
				
				// Fetch status code
				$statusCode = $response->getStatusCode();
				
				// If invalid, throw
				if ($statusCode >= 400 || empty($body['accessToken'])) {
					$message = $body['detail'] ?? $body['title'] ?? "Authentication failed (HTTP {$statusCode})";
					throw new \RuntimeException("DHL authentication failed: {$message}");
				}
				
				// Store the tokens
				$this->storeTokens($body);
			} catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				throw new \RuntimeException("DHL authentication request failed: {$e->getMessage()}", 0, $e);
			}
		}
		
		/**
		 * Obtains a new access token using the stored refresh token.
		 * Falls back to full re-authentication if the refresh token has expired.
		 * @return void
		 */
		private function refreshAccessToken(): void {
			// Call API
			try {
				$response = $this->client->request('POST', $this->refreshUrl, [
					'headers' => [
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					'json'    => ['refreshToken' => $this->refreshToken],
				]);
				// Decode the contents
				$body = json_decode($response->getContent(false), true);
				
				// Error if invalid JSON
				if (json_last_error() !== JSON_ERROR_NONE) {
					throw new \RuntimeException("DHL refresh authentication failed: " . json_last_error_msg(), json_last_error());
				}
				
				// Fetch the status code
				$statusCode = $response->getStatusCode();
				
				// Refresh token may itself have expired — fall back to full authentication
				if ($statusCode >= 400 || empty($body['accessToken'])) {
					$this->refreshToken = null;
					$this->authenticate();
				} else {
					$this->storeTokens($body);
				}
			} catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				$this->refreshToken = null;
				$this->authenticate();
			}
		}
		
		/**
		 * Stores tokens and their expiration times from an authentication response body.
		 * @param array<string, mixed> $body Decoded authentication response
		 */
		private function storeTokens(array $body): void {
			$this->accessToken = $body['accessToken'];
			$this->accessTokenExpiration = (int)($body['accessTokenExpiration'] ?? 0);
			$this->refreshToken = $body['refreshToken'] ?? null;
			$this->refreshTokenExpiration = (int)($body['refreshTokenExpiration'] ?? 0);
		}
	}