<?php
	
	namespace Quellabs\Payments\Adyen;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
	
	/**
	 * Low-level wrapper around the Adyen Checkout REST API (v71).
	 * Handles raw HTTP communication, authentication, and response normalisation.
	 * All methods return a normalised array:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [...]]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 * @see https://docs.adyen.com/api-explorer/Checkout/71/overview
	 */
	class AdyenGateway {
		
		private const CHECKOUT_VERSION = 'v71';
		
		private string $baseUrl;
		private string $apiKey;
		private string $merchantAccount;
		
		/**
		 * AdyenGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			$this->apiKey          = $config['api_key'] ?? '';
			$this->merchantAccount = $config['merchant_account'] ?? '';
			
			// Live endpoint prefix differs per merchant; for testing the test endpoint is fixed.
			// In production you must replace 'YOUR_LIVE_PREFIX' with the prefix from your Customer Area.
			// @see https://docs.adyen.com/development-resources/live-endpoints
			if ($config['test_mode']) {
				$this->baseUrl = 'https://checkout-test.adyen.com/' . self::CHECKOUT_VERSION;
			} else {
				$livePrefix    = $config['live_endpoint_prefix'] ?? '';
				$this->baseUrl = "https://{$livePrefix}-checkout-live.adyenpayments.com/checkout/" . self::CHECKOUT_VERSION;
			}
		}
		
		/**
		 * Creates an Adyen payment session (Sessions flow).
		 * The returned sessionId + sessionData are passed to the front-end Drop-in component.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/sessions
		 * @param array $payload Request body — must include merchantAccount, amount, reference, returnUrl
		 * @return array
		 */
		public function createSession(array $payload): array {
			return $this->post('/sessions', $payload);
		}
		
		/**
		 * Submits a redirectResult returned by the Drop-in after a payment redirect.
		 * Required for payment methods that redirect the shopper away from your site
		 * (e.g. iDEAL, 3DS redirect). Not needed when Drop-in handles the entire flow inline.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/payments/details
		 * @param array $payload Must contain details['redirectResult'], optionally paymentData
		 * @return array
		 */
		public function getPaymentDetails(array $payload): array {
			return $this->post('/payments/details', $payload);
		}
		
		/**
		 * Returns the list of payment methods available for a transaction context.
		 * The response is filtered by Adyen based on the merchant account configuration,
		 * amount, currency, and country — so always pass all three for accurate results.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/paymentMethods
		 * @param array $payload Must include merchantAccount; recommended: amount, currency, countryCode
		 * @return array
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
		 * @return array
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
		 * @param array $notification The decoded NotificationRequestItem
		 * @param string $hmacKey The hex-encoded HMAC key from your Adyen Customer Area
		 * @return bool
		 */
		public function verifyHmacSignature(array $notification, string $hmacKey): bool {
			if (empty($hmacKey)) {
				return false;
			}
			
			$receivedSignature = $notification['additionalData']['hmacSignature'] ?? null;
			
			if (empty($receivedSignature)) {
				return false;
			}
			
			// Build the signing string from the fixed set of fields Adyen uses.
			// The field order is strictly defined by Adyen — do not reorder.
			// @see https://docs.adyen.com/development-resources/webhooks/verify-hmac-signatures#hmac-signature-calculation
			$signingString = implode(':', [
				$this->escapeHmacValue((string)($notification['pspReference'] ?? '')),
				$this->escapeHmacValue((string)($notification['originalReference'] ?? '')),
				$this->escapeHmacValue((string)($notification['merchantAccountCode'] ?? '')),
				$this->escapeHmacValue((string)($notification['merchantReference'] ?? '')),
				$this->escapeHmacValue((string)($notification['amount']['value'] ?? '')),
				$this->escapeHmacValue((string)($notification['amount']['currency'] ?? '')),
				$this->escapeHmacValue((string)($notification['eventCode'] ?? '')),
				$this->escapeHmacValue((string)($notification['success'] ?? '')),
			]);
			
			$binaryKey       = pack('H*', $hmacKey);
			$expectedRaw     = hash_hmac('sha256', $signingString, $binaryKey, true);
			$expectedEncoded = base64_encode($expectedRaw);
			
			return hash_equals($expectedEncoded, $receivedSignature);
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
		 * @param array $payload JSON request body
		 * @return array
		 */
		private function post(string $endpoint, array $payload): array {
			$client = HttpClient::create();
			
			try {
				$response = $client->request('POST', $this->baseUrl . $endpoint, [
					'headers' => [
						'Content-Type' => 'application/json',
						'X-API-Key'    => $this->apiKey,
					],
					'json'    => $payload,
				]);
				
				$statusCode = $response->getStatusCode();
				$body       = json_decode($response->getContent(false), true);
				
				// Adyen returns HTTP 4xx/5xx for API-level errors, with a JSON body containing
				// 'errorCode' and 'message'. A 2xx response always indicates the request was accepted.
				if ($statusCode >= 400) {
					$errorCode    = $body['errorCode'] ?? $statusCode;
					$errorMessage = $body['message'] ?? "HTTP {$statusCode}";
					return ['request' => ['result' => 0, 'errorId' => $errorCode, 'errorMessage' => $errorMessage]];
				}
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $body];
			} catch (\Exception|TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				// Network or HTTP-level failure — the request never reached Adyen or couldn't be read
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
	}