<?php
	
	namespace Quellabs\Shipments\DHL;
	
	use Symfony\Component\HttpClient\HttpClient;
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
	 */
	class DHLGateway {
		
		/** DHL Parcel NL production base URL */
		public const BASE_URL = 'https://api-gw.dhlparcel.nl';
		
		/** DHL authentication endpoint */
		private const AUTH_URL = self::BASE_URL . '/authenticate/api-key';
		
		/** DHL token refresh endpoint */
		private const REFRESH_URL = self::BASE_URL . '/authenticate/refresh-token';
		
		/** @var HttpClientInterface Shared HTTP client (no Authorization header — added per-request) */
		private HttpClientInterface $client;
		
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
			$this->userId = $config['user_id'];
			$this->apiKey = ($config['mode'] === 'live') ? $config['api_key'] : ($config['api_key_test'] ?? $config['api_key']);
			
			$this->client = HttpClient::create(['timeout' => 15]);
		}
		
		/**
		 * Creates a shipment and returns the trackerCode (barcode) and internal shipment data.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/#/Shipments/post_shipments
		 * @param array $payload Full shipment payload (shipmentId, receiver, shipper, options, pieces)
		 * @return array
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
		 * @return array
		 */
		public function getLabelId(string $trackerCode): array {
			return $this->get('/labels', ['trackerCodeFilter' => $trackerCode]);
		}
		
		/**
		 * Fetches the raw PDF bytes for a label by its internal label ID.
		 * Returns the binary PDF content in response['pdf'] on success.
		 *
		 * Callers that need to serve the label to a browser or printer should use this
		 * rather than getLabelUrl(), which returns a URL that requires a valid Bearer token.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/#/Labels/get_labels__labelId_
		 * @param string $labelId Internal label ID from getLabelId()
		 * @return array ['request' => [...], 'response' => ['pdf' => <binary>]]
		 */
		public function getLabelPdf(string $labelId): array {
			try {
				$token = $this->getValidToken();
				$response = $this->client->request('GET', self::BASE_URL . "/labels/{$labelId}", [
					'headers' => [
						'Authorization' => "Bearer {$token}",
						'Accept'        => 'application/pdf',
					],
				]);
				
				$statusCode = $response->getStatusCode();
				
				if ($statusCode >= 400) {
					return $this->errorResult($statusCode, "HTTP {$statusCode}");
				}
				
				return [
					'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
					'response' => ['pdf' => $response->getContent()],
				];
			} catch (\Throwable $e) {
				return $this->errorResult($e->getCode(), $e->getMessage());
			}
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
		 * @return array
		 */
		public function getTrackTrace(string $trackerCode): array {
			// Track-trace endpoint does not require authentication
			try {
				$response = $this->client->request('GET', self::BASE_URL . '/track-trace', [
					'headers' => ['Accept' => 'application/json'],
					'query'   => ['key' => $trackerCode],
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return $this->errorResult($e->getCode(), $e->getMessage());
			}
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
		 * @return array
		 */
		public function getParcelShops(string $countryCode, ?string $postalCode = null, ?string $city = null, int $limit = 10): array {
			$query = array_filter([
				'zipCode' => $postalCode,
				'city'    => $city,
				'limit'   => $limit,
			], fn($v) => $v !== null && $v !== '');
			
			try {
				$response = $this->client->request('GET', self::BASE_URL . "/parcel-shop-locations/{$countryCode}", [
					'headers' => ['Accept' => 'application/json'],
					'query'   => $query,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return $this->errorResult($e->getCode(), $e->getMessage());
			}
		}
		
		/**
		 * Sends an authenticated GET request.
		 * @param string $endpoint Path relative to BASE_URL
		 * @param array $query Optional query parameters
		 * @return array
		 */
		private function get(string $endpoint, array $query = []): array {
			try {
				$token = $this->getValidToken();
				$response = $this->client->request('GET', self::BASE_URL . $endpoint, [
					'headers' => [
						'Authorization' => "Bearer {$token}",
						'Accept'        => 'application/json',
					],
					'query'   => $query,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return $this->errorResult($e->getCode(), $e->getMessage());
			}
		}
		
		/**
		 * Sends an authenticated POST request.
		 * @param string $endpoint Path relative to BASE_URL
		 * @param array $payload JSON request body
		 * @return array
		 */
		private function post(string $endpoint, array $payload): array {
			try {
				$token = $this->getValidToken();
				$response = $this->client->request('POST', self::BASE_URL . $endpoint, [
					'headers' => [
						'Authorization' => "Bearer {$token}",
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					],
					'json'    => $payload,
				]);
				
				return $this->normaliseResponse($response);
			} catch (\Throwable $e) {
				return $this->errorResult($e->getCode(), $e->getMessage());
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
		 * @return string
		 * @throws \RuntimeException when authentication fails
		 */
		private function getValidToken(): string {
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
				$response = $this->client->request('POST', self::AUTH_URL, [
					'headers' => [
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					'json'    => [
						'userId' => $this->userId,
						'key'    => $this->apiKey,
					],
				]);
				
				$body = json_decode($response->getContent(false), true);
				$statusCode = $response->getStatusCode();
				
				if ($statusCode >= 400 || empty($body['accessToken'])) {
					$message = $body['detail'] ?? $body['title'] ?? "Authentication failed (HTTP {$statusCode})";
					throw new \RuntimeException("DHL authentication failed: {$message}");
				}
				
				$this->storeTokens($body);
			} catch (\RuntimeException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new \RuntimeException("DHL authentication request failed: {$e->getMessage()}", 0, $e);
			}
		}
		
		/**
		 * Obtains a new access token using the stored refresh token.
		 * @throws \RuntimeException on refresh failure (triggers full re-authentication)
		 */
		private function refreshAccessToken(): void {
			try {
				$response = $this->client->request('POST', self::REFRESH_URL, [
					'headers' => [
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					'json'    => ['refreshToken' => $this->refreshToken],
				]);
				
				$body = json_decode($response->getContent(false), true);
				$statusCode = $response->getStatusCode();
				
				if ($statusCode >= 400 || empty($body['accessToken'])) {
					// Refresh token may itself have expired — fall back to full authentication
					$this->refreshToken = null;
					$this->authenticate();
					return;
				}
				
				$this->storeTokens($body);
			} catch (\RuntimeException $e) {
				throw $e;
			} catch (\Throwable $e) {
				// Network failure during refresh — attempt full re-auth
				$this->refreshToken = null;
				$this->authenticate();
			}
		}
		
		/**
		 * Stores tokens and their expiration times from an authentication response body.
		 * @param array $body Decoded authentication response
		 */
		private function storeTokens(array $body): void {
			$this->accessToken = $body['accessToken'];
			$this->accessTokenExpiration = (int)($body['accessTokenExpiration'] ?? 0);
			$this->refreshToken = $body['refreshToken'] ?? null;
			$this->refreshTokenExpiration = (int)($body['refreshTokenExpiration'] ?? 0);
		}
		
		/**
		 * Normalises an HTTP response into the shared result envelope.
		 *
		 * DHL error response shape:
		 *   HTTP 4xx/5xx with JSON: { "title": "...", "detail": "...", "status": 422 }
		 *   The "detail" field is the most descriptive; "title" is the fallback.
		 *
		 * @param \Symfony\Contracts\HttpClient\ResponseInterface $response
		 * @return array
		 */
		private function normaliseResponse(\Symfony\Contracts\HttpClient\ResponseInterface $response): array {
			$statusCode = $response->getStatusCode();
			
			// getContent(false) suppresses exceptions on 4xx/5xx so we can read the body
			$rawBody = $response->getContent(false);
			$body = json_decode($rawBody, true);
			
			if ($statusCode >= 400) {
				if (is_array($body)) {
					$message = $body['detail'] ?? $body['title'] ?? "HTTP {$statusCode}";
					
					// Append field-level validation errors when present
					if (!empty($body['errors']) && is_array($body['errors'])) {
						$firstError = reset($body['errors']);
						$message .= is_string($firstError) ? " ({$firstError})" : '';
					}
				} else {
					$message = $rawBody ?: "HTTP {$statusCode}";
				}
				
				return $this->errorResult($statusCode, $message);
			}
			
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => $body,
			];
		}
		
		/**
		 * Returns a normalised error result array.
		 * @param int|string $errorId
		 * @param string $message
		 * @return array
		 */
		private function errorResult(int|string $errorId, string $message): array {
			return [
				'request' => [
					'result'       => 0,
					'errorId'      => $errorId,
					'errorMessage' => $message,
				],
			];
		}
	}
