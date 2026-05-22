<?php
	
	namespace Quellabs\Payments\Adyen;
	
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the Adyen Checkout REST API (v71).
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 * All methods return a normalized array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 * @see https://docs.adyen.com/api-explorer/Checkout/71/overview
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class AdyenGateway {
		
		// Adyen Checkout API version — update here when migrating to a newer version.
		private const string CHECKOUT_VERSION = 'v71';
		
		/** @var HttpClientInterface // Shared HTTP client instance */
		private HttpClientInterface $client;
		
		/** @var string Test or live endpoint, resolved from config at construction */
		private string $baseUrl;
		
		/** @var string X-API-Key header value for all requests */
		private string $apiKey;
		
		/** @var string Identifies which merchant account processes the payment */
		private string $merchantAccount;
		
		/**
		 * AdyenGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			// Fetch config from driver
			$config = $driver->getConfig();
			
			// Extract information
			$this->client = HttpClient::create(['timeout' => 10]);
			$this->apiKey = is_string($config['api_key'] ?? null) ? $config['api_key'] : '';
			$this->merchantAccount = is_string($config['merchant_account'] ?? null) ? $config['merchant_account'] : '';
			
			// Live endpoint prefix differs per merchant; for testing the test endpoint is fixed.
			// In production, you must replace 'YOUR_LIVE_PREFIX' with the prefix from your Customer Area.
			// @see https://docs.adyen.com/development-resources/live-endpoints
			if ($config['test_mode'] ?? false) {
				$this->baseUrl = 'https://checkout-test.adyen.com/' . self::CHECKOUT_VERSION;
			} else {
				$livePrefix = is_string($config['live_endpoint_prefix'] ?? null) ? $config['live_endpoint_prefix'] : '';
				$this->baseUrl = "https://{$livePrefix}-checkout-live.adyenpayments.com/checkout/" . self::CHECKOUT_VERSION;
			}
		}
		
		/**
		 * Creates an Adyen Pay by Link hosted payment page.
		 * Returns a url the shopper is redirected to. After payment, Adyen redirects them
		 * back to the returnUrl with ?redirectResult= appended.
		 * @see https://docs.adyen.com/unified-commerce/pay-by-link/create-payment-links/api
		 * @param array<string, mixed> $payload Request body — must include merchantAccount, amount, reference, returnUrl
		 * @return GatewayResponse
		 */
		public function createPaymentLink(array $payload): array {
			return $this->post('/paymentLinks', $payload);
		}
		
		/**
		 * Resolves the final payment status after the shopper returns from the hosted payment page.
		 * Adyen appends ?redirectResult= to the returnUrl — submit it here to get the resultCode.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/payments/details
		 * @param array<string, mixed> $payload Must contain details['redirectResult'], optionally paymentData
		 * @return GatewayResponse
		 */
		public function getPaymentDetails(array $payload): array {
			return $this->post('/payments/details', $payload);
		}
		
		/**
		 * Returns the list of payment methods available for a transaction context.
		 * The response is filtered by Adyen based on the merchant account configuration,
		 * amount, currency, and country — so always pass all three for accurate results.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/paymentMethods
		 * @param array<string, mixed> $payload Must include merchantAccount; recommended: amount, currency, countryCode
		 * @return GatewayResponse
		 */
		public function getPaymentMethods(array $payload): array {
			return $this->post('/paymentMethods', $payload);
		}
		
		/**
		 * Refunds a previously captured payment.
		 * Adyen returns status='received' immediately; the final outcome arrives via a REFUND webhook.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/payments/{paymentPspReference}/refunds
		 * @param string $pspReference The pspReference of the original authorised payment
		 * @param int $amount Refund amount in minor units (e.g. 1250 = €12.50)
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR')
		 * @param string $note Human-readable reason for the refund (shown in Customer Area)
		 * @return GatewayResponse
		 */
		public function refundPayment(string $pspReference, int $amount, string $currency, string $note = ''): array {
			$payload = [
				'merchantAccount' => $this->merchantAccount,
				'amount'          => [
					'value'    => $amount,
					'currency' => $currency,
				],
				'reference'       => $note,
			];
			
			return $this->post("/payments/{$pspReference}/refunds", $payload);
		}
		
		/**
		 * Verifies the HMAC-SHA256 signature on an Adyen webhook notification.
		 * Adyen signs the notification with a shared secret configured in your Customer Area.
		 * The signature is included in additionalData.hmacSignature.
		 * Uses hash_equals for timing-safe comparison to prevent timing attacks.
		 * @see https://docs.adyen.com/development-resources/webhooks/verify-hmac-signatures
		 * @param array<string, mixed> $notification The decoded NotificationRequestItem
		 * @param string $hmacKey The hex-encoded HMAC key from your Adyen Customer Area
		 * @return bool
		 */
		public function verifyHmacSignature(array $notification, string $hmacKey): bool {
			// Bail if no hmacKey passed
			if (empty($hmacKey)) {
				return false;
			}
			
			// Extract hmacSignature — additionalData is mixed, assert array before indexing
			$additionalData = is_array($notification['additionalData'] ?? null) ? $notification['additionalData'] : [];
			
			if (isset($additionalData['hmacSignature']) && is_string($additionalData['hmacSignature'])) {
				$receivedSignature = $additionalData['hmacSignature'];
			} else {
				$receivedSignature = null;
			}
			
			// Bail if none found
			if (empty($receivedSignature)) {
				return false;
			}
			
			// Build the signing string from the fixed set of fields Adyen uses.
			// The field order is strictly defined by Adyen — do not reorder.
			// @see https://docs.adyen.com/development-resources/webhooks/verify-hmac-signatures#hmac-signature-calculation
			$amountArr = is_array($notification['amount'] ?? null) ? $notification['amount'] : [];
			/** @var array<string, mixed> $amountArr */
			$signingData = $notification + ['amount.value' => $amountArr['value'] ?? null, 'amount.currency' => $amountArr['currency'] ?? null];
			$signingKeys  = ['pspReference', 'originalReference', 'merchantAccountCode', 'merchantReference', 'amount.value', 'amount.currency', 'eventCode', 'success'];
			
			$signingParts = [];
			foreach ($signingKeys as $key) {
				$v = $signingData[$key] ?? null;
				
				if (is_int($v)) {
					$signingParts[] = $this->escapeHmacValue((string)$v);
				} elseif (is_string($v)) {
					$signingParts[] = $this->escapeHmacValue(($v));
				} else {
					$signingParts[] = $this->escapeHmacValue((''));
				}
			}
			
			$signingString = implode(':', $signingParts);
			
			// Build the hashes to compare
			$binaryKey = pack('H*', $hmacKey);
			$expectedRaw = hash_hmac('sha256', $signingString, $binaryKey, true);
			$expectedEncoded = base64_encode($expectedRaw);
			
			// Do the comparison
			return hash_equals($expectedEncoded, (string)$receivedSignature);
		}
		
		/**
		 * Escapes a value for inclusion in the HMAC signing string.
		 * Adyen's spec requires that backslashes and colons are escaped with a backslash.
		 * @param string $value
		 * @return string
		 */
		private function escapeHmacValue(string $value): string {
			return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
		}
		
		/**
		 * Sends a POST request to the Adyen Checkout API and returns a normalised response array.
		 * All Checkout API methods funnel through here to keep HTTP handling in one place.
		 * @param string $endpoint Path relative to the versioned base URL (e.g. '/sessions')
		 * @param array<string, mixed> $payload JSON request body
		 * @return GatewayResponse
		 */
		private function post(string $endpoint, array $payload): array {
			// Headers
			$headers = [
				'Content-Type' => 'application/json',
				'X-API-Key'    => $this->apiKey,
			];
			
			// Attach an idempotency key when a reference is present so that retrying the same
			// request (e.g. after a network timeout) returns the existing resource rather than
			// creating a duplicate.
			if (!empty($payload['reference'])) {
				$headers['Idempotency-Key'] = $payload['reference'];
			}
			
			try {
				$response = $this->client->request('POST', $this->baseUrl . $endpoint, [
					'headers' => $headers,
					'json'    => $payload,
				]);
				
				$statusCode = $response->getStatusCode();
				$decoded = json_decode($response->getContent(false), true);
				
				// Validate json decode succeeded
				if (json_last_error() !== JSON_ERROR_NONE) {
					return ['request' => ['result' => 0, 'errorId' => (string)json_last_error(), 'errorMessage' => json_last_error_msg()]];
				}
				
				if (!is_array($decoded)) {
					return ['request' => ['result' => 0, 'errorId' => '400', 'errorMessage' => "Empty JSON response"]];
				}
				
				// Adyen always returns a JSON object (not an array), so keys are always strings.
				/** @var array<string, mixed> $decoded */
				
				// Adyen returns HTTP 4xx/5xx for API-level errors, with a JSON body containing
				// 'errorCode' and 'message'. A 2xx response always indicates the request was accepted.
				if ($statusCode >= 400) {
					$errorCode = is_string($decoded['errorCode'] ?? null) ? $decoded['errorCode'] : (string)$statusCode;
					$errorMessage = is_string($decoded['message'] ?? null) ? $decoded['message'] : "HTTP {$statusCode}";
					return ['request' => ['result' => 0, 'errorId' => $errorCode, 'errorMessage' => $errorMessage]];
				}
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $decoded];
			} catch (\Throwable $e) {
				// Network or HTTP-level failure — the request never reached Adyen or couldn't be read
				return ['request' => ['result' => 0, 'errorId' => (string)$e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
	}