<?php
	
	namespace Quellabs\Payments\Buckaroo;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the Buckaroo JSON API (BPE 3.0).
	 * Handles raw HTTP communication, HMAC authentication, and response normalisation.
	 *
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * Authentication: every request is signed with HMAC-SHA256.
	 * The Authorization header format is: hmac <websiteKey>:<base64Hash>:<nonce>:<timestamp>
	 * Hash input (concatenated): websiteKey + httpMethod + urlEncode(host+path, lowercase) + timestamp + nonce + base64(md5(requestBody))
	 *
	 * Test vs live: separate hostnames — not a flag on the same endpoint.
	 *
	 * Amounts in Buckaroo are decimal floats (e.g. 10.00 = €10.00), NOT minor units.
	 * Callers are responsible for converting minor-unit amounts before invoking these methods.
	 *
	 * @see https://docs.buckaroo.io/docs/apis
	 * @see https://docs.buckaroo.io/docs/integration-hmac
	 */
	class BuckarooGateway {
		
		/** @var string Buckaroo JSON API base host (no trailing slash, no path) */
		private string $baseHost;
		
		/** @var string Website key (public identifier for the Buckaroo site) */
		private string $websiteKey;
		
		/** @var string Secret key used for HMAC signing */
		private string $secretKey;
		
		/** @var HttpClientInterface Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/**
		 * BuckarooGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			$this->client     = HttpClient::create();
			$this->websiteKey = $config['website_key'] ?? '';
			$this->secretKey  = $config['secret_key']  ?? '';
			
			// Buckaroo uses completely separate hostnames for test and live.
			// @see https://docs.buckaroo.io/docs/integration-testing
			if ($config['test_mode']) {
				$this->baseHost = 'testcheckout.buckaroo.nl';
			} else {
				$this->baseHost = 'checkout.buckaroo.nl';
			}
		}
		
		/**
		 * Creates a new transaction (initiates a payment).
		 * Returns a RequiredAction.RedirectURL to send the shopper to.
		 * @see https://docs.buckaroo.io/docs/transaction-post
		 * @param array $payload Full transaction payload per Buckaroo spec
		 * @return array Normalised response
		 */
		public function createTransaction(array $payload): array {
			return $this->request('POST', '/json/Transaction', $payload);
		}
		
		/**
		 * Fetches the current status of a transaction by its Buckaroo transaction key.
		 *
		 * This is the authoritative status source for both the return URL and push flows —
		 * the return URL query params and the push body carry only the key, not the status.
		 *
		 * Note: RelatedTransactions in the status response contains only keys (no amounts).
		 * Use getTransactionStatus() on each refund key to retrieve amounts.
		 *
		 * @see https://docs.buckaroo.io/docs/transaction-get
		 * @param string $transactionKey Buckaroo's own 32-char transaction key (from the Key field)
		 * @return array Normalised response
		 */
		public function getTransactionStatus(string $transactionKey): array {
			return $this->request('GET', '/json/Transaction/Status/' . urlencode($transactionKey));
		}
		
		/**
		 * Fetches the list of iDEAL issuers (participating banks) via the
		 * TransactionRequestSpecification endpoint.
		 *
		 * This is the correct endpoint for the JSON API. It returns a list of services
		 * with parameters; for iDEAL the parameter list contains the issuer codes and names.
		 *
		 * @see https://docs.buckaroo.io/docs/ideal-requests
		 * @return array Normalised response
		 */
		public function getIdealIssuers(): array {
			return $this->request('GET', '/json/Transaction/Specification/ideal');
		}
		
		/**
		 * Submits a refund for a completed transaction.
		 * Uses AmountCredit + OriginalTransactionKey + Action: 'Refund'.
		 * Omitting AmountCredit triggers a full refund.
		 * @see https://docs.buckaroo.io/docs/refunds
		 * @param array $payload Must include currency, OriginalTransactionKey, and optionally AmountCredit
		 * @return array Normalised response containing the refund transaction Key
		 */
		public function refundTransaction(array $payload): array {
			return $this->request('POST', '/json/Transaction', $payload);
		}
		
		/**
		 * Builds and sends an HMAC-authenticated request to the Buckaroo JSON API.
		 *
		 * Buckaroo may return non-200 HTTP codes for actual errors; the status code is
		 * therefore meaningful here (unlike MultiSafepay which always returns 200).
		 * A RequestErrors array in the response body indicates a validation failure.
		 *
		 * HMAC construction:
		 *   1. Compute MD5 of the raw JSON body, then base64-encode → contentHash
		 *   2. Concatenate: websiteKey + METHOD + urlencode(host+path, lowercase) + timestamp + nonce + contentHash
		 *   3. HMAC-SHA256 that string with the secretKey, then base64-encode → hash
		 *   4. Authorization header: hmac websiteKey:hash:nonce:timestamp
		 *
		 * @see https://docs.buckaroo.io/docs/json-authenticator
		 * @param string $method HTTP method ('GET' or 'POST')
		 * @param string $path Path relative to the API host (must start with '/')
		 * @param array|null $payload JSON request body (POST only; omit for GET)
		 * @return array Normalised response
		 */
		private function request(string $method, string $path, ?array $payload = null): array {
			try {
				$url        = 'https://' . $this->baseHost . $path;
				$body       = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : '';
				$nonce      = bin2hex(random_bytes(8));
				$timestamp  = time();
				
				$options = [
					'headers' => [
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
						'Authorization' => $this->buildAuthorizationHeader($method, $path, $body, $nonce, $timestamp),
						'Culture'       => 'nl-NL',
					],
				];
				
				if ($payload !== null) {
					$options['body'] = $body;
				}
				
				$response = $this->client->request($method, $url, $options);
				$httpCode = $response->getStatusCode();
				$rawBody  = $response->getContent(false);
				
				// Decode JSON body
				$data = json_decode($rawBody, true);
				
				// If that failed, return error
				if (json_last_error() !== JSON_ERROR_NONE) {
					return ['request' => ['result' => 0, 'errorId' => 0, 'errorMessage' => 'Invalid JSON response (HTTP ' . $httpCode . '): ' . json_last_error_msg()]];
				}
				
				// RequestErrors indicates a validation/authentication failure
				if (!empty($data['RequestErrors'])) {
					$firstError = reset($data['RequestErrors']);
					
					if (is_array($firstError)) {
						$firstError = reset($firstError);
					}
					
					$errorCode = $firstError['ErrorCode'] ?? $firstError['Code'] ?? 0;
					$errorMsg  = $firstError['ErrorMessage'] ?? $firstError['Description'] ?? 'Request error';
					return ['request' => ['result' => 0, 'errorId' => $errorCode, 'errorMessage' => $errorMsg]];
				}
				
				// Non-2xx HTTP codes with no RequestErrors: surface the HTTP code as the error
				if ($httpCode < 200 || $httpCode >= 300) {
					return ['request' => ['result' => 0, 'errorId' => $httpCode, 'errorMessage' => 'HTTP error ' . $httpCode]];
				}
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $data];
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Builds the HMAC Authorization header value.
		 * For GET requests (no body), the content hash component is an empty string.
		 * The URI component is the lowercase URL-encoded form of host+path (no scheme, no query).
		 * @param string $method   HTTP method (uppercase)
		 * @param string $path     Path component only (e.g. '/json/Transaction')
		 * @param string $body     Raw JSON body string (empty string for GET)
		 * @param string $nonce    Random nonce string
		 * @param int    $timestamp Unix timestamp
		 * @return string          Full Authorization header value (including 'hmac ' prefix)
		 */
		private function buildAuthorizationHeader(string $method, string $path, string $body, string $nonce, int $timestamp): string {
			// Content hash: base64(MD5(body)) — empty string for GET requests without body
			$contentHash = '';
			
			if ($body !== '') {
				$contentHash = base64_encode(md5($body, true));
			}
			
			// URI component: lowercase(urlencode(host + path)) — no scheme, no query string
			$uriComponent = strtolower(urlencode($this->baseHost . $path));
			
			// Concatenate the signing string
			$signingString = $this->websiteKey . strtoupper($method) . $uriComponent . $timestamp . $nonce . $contentHash;
			
			// HMAC-SHA256 and base64-encode
			$hash = base64_encode(hash_hmac('sha256', $signingString, $this->secretKey, true));
			return 'hmac ' . $this->websiteKey . ':' . $hash . ':' . $nonce . ':' . $timestamp;
		}
		
	}