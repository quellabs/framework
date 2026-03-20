<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Support\Resources;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
	
	class MollieGateway {
		
		protected string $apiKey;
		protected bool $testMode;
		
		/**
		 * Mollie constructor.
		 */
		public function __construct(Driver $driver) {
			$configData = $driver->getConfig();
			$this->apiKey = $configData["api_key"] ?? "";
			$this->testMode = $configData["test_mode"] ?? false;
		}
		
		/**
		 * Call the API and return the result
		 * @param string $method
		 * @param string $action
		 * @param array $data
		 * @return array
		 */
		protected function callHttpClient(string $method, string $action, array $data = []): array {
			try {
				$client = HttpClient::create([
					'base_uri'    => 'https://api.mollie.nl/v2/',
					'timeout'     => 10,
					'headers'     => [
						'Accept'        => 'application/json',
						'Authorization' => "Bearer {$this->apiKey}",
						'Content-Type'  => 'application/json',
					],
					
					// Enforce strict SSL verification using the bundled CA certificate
					'verify_peer' => true,
					'verify_host' => true,
					'cafile'      => Resources::cacertPem()
				]);
				
				$response = $client->request($method, $action, ['json' => $data]);
				$statusCode = $response->getStatusCode();
				$jsonData = $response->toArray();
			} catch (\Exception|TransportExceptionInterface|DecodingExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				// Network failure, timeout, or non-2xx response — return a normalized error
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
			
			// Single-resource responses (payment, method, refund) are returned as-is
			if (isset($jsonData['resource']) && in_array($jsonData['resource'], ['payment', 'method', 'refund'])) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $jsonData];
			}
			
			// List responses are wrapped in _embedded — unwrap the first collection regardless of key name
			// (methods use 'methods', refunds use 'refunds', etc.)
			if (!empty($jsonData['_embedded'])) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => reset($jsonData['_embedded'])];
			}
			
			// Anything else is an API-level error — surface the status code and detail message
			return ['request' => ['result' => 0, 'errorId' => $statusCode, 'errorMessage' => $jsonData['detail']]];
		}
		
		/**
		 * Retrieve all payments created with the current website profile, ordered from newest to oldest.
		 * @url https://docs.mollie.com/reference/v2/payments-api/list-payments
		 * @return array
		 */
		public function listPayments(): array {
			return $this->callHttpClient("GET", "payments");
		}
		
		/**
		 * Returns all iDeal issuers
		 * @return array
		 */
		public function listIssuers(): array {
			return $this->callHttpClient("GET", "issuers");
		}
		
		/**
		 * Retrieve all available payment methods. The results are not paginated.
		 * @url https://docs.mollie.com/reference/v2/methods-api/list-methods
		 * @param string $sequenceType
		 * @return array
		 */
		public function getPaymentMethods(string $sequenceType = "oneoff"): array {
			return $this->callHttpClient("GET", "methods?sequenceType={$sequenceType}&include=issuers");
		}
		
		/**
		 * Retrieve all available payment methods. The results are not paginated.
		 * @url https://docs.mollie.com/reference/v2/methods-api/list-all-methods
		 * @return array
		 */
		public function getAllPaymentMethods(): array {
			return $this->callHttpClient("GET", "methods/all");
		}
		
		/**
		 * Retrieve all available payment methods. The results are not paginated.
		 * @url https://docs.mollie.com/reference/v2/methods-api/list-methods
		 * @param string $method
		 * @return array
		 */
		public function getPaymentMethodInfo(string $method): array {
			return $this->callHttpClient("GET", "methods/{$method}?include=issuers");
		}
		
		/**
		 * Retrieve all received chargebacks. If the payment-specific endpoint is used, only
		 * chargebacks for that specific payment are returned.
		 * @url https://docs.mollie.com/reference/v2/chargebacks-api/list-chargebacks
		 * @param string $paymentId
		 * @return array
		 */
		public function listChargebacks(string $paymentId): array {
			return $this->callHttpClient("GET", "payments/{$paymentId}/chargebacks");
		}
		
		/**
		 * Retrieve all received chargebacks. If the payment-specific endpoint is used, only
		 * chargebacks for that specific payment are returned.
		 * @url https://docs.mollie.com/reference/v2/chargebacks-api/list-chargebacks
		 * @return array
		 */
		public function listAllChargebacks(): array {
			return $this->callHttpClient("GET", "chargebacks");
		}
		
		/**
		 * Retrieve a single chargeback by its ID. Note the original payment’s ID is needed as well.
		 * Example: /v2/payments/tr_7UhSN1zuXS/chargebacks/chb_n9z0tp
		 * @url https://docs.mollie.com/reference/v2/chargebacks-api/get-chargeback
		 * @param string $paymentId
		 * @param string $chargebackId
		 * @return array
		 */
		public function getChargebackInfo(string $paymentId, string $chargebackId): array {
			return $this->callHttpClient("GET", "payments/{$paymentId}/chargebacks/{$chargebackId}");
		}
		
		/**
		 * Refund a mollie payment
		 * @param string $transactionId
		 * @param int|null $amount
		 * @param string $currency
		 * @param string|null $description
		 * @return array
		 */
		public function createRefund(string $transactionId, ?int $amount, string $currency, ?string $description = null): array {
			// Error when no transactionId passed
			if (empty($transactionId)) {
				return ['request' => ['result' => 0, 'errorId' => 500, 'errorMessage' => 'Missing transactionId']];
			}
			
			// Error when trying to refund €0.00
			if ($amount === 0) {
				return ['request' => ['result' => 0, 'errorId' => 500, 'errorMessage' => 'Invalid refund value']];
			}
			
			// Resolve the refund amount and currency
			$resolved = $this->resolveRefundAmount($transactionId, $amount, $currency);
			
			// If that failed, return an error
			if ($resolved['request']['result'] === 0) {
				return $resolved;
			}
			
			// Issue the refund
			return $this->callHttpClient("POST", "payments/{$transactionId}/refunds", [
				"amount"      => [
					"currency" => $resolved['response']['currency'],
					"value"    => $resolved['response']['amount'],
				],
				"description" => $description,
				"testmode"    => $this->testMode,
			]);
		}
		
		/**
		 * Returns information about a Mollie payment
		 * @url https://docs.mollie.com/reference/v2/payments-api/get-payment
		 * @param string $transactionId
		 * @return array
		 */
		public function getPaymentInfo(string $transactionId): array {
			return $this->callHttpClient("GET", "payments/{$transactionId}");
		}
		
		/**
		 * Creates a new Mollie payment
		 * @url https://docs.mollie.com/reference/create-payment
		 * @param array $payload Raw Mollie payment payload, already serialized and in Mollie's expected shape
		 * @return array
		 */
		public function createPayment(array $payload): array {
			return $this->callHttpClient('POST', 'payments', $payload);
		}
		
		/**
		 * Retrieve all refunds for a payment
		 * @url https://docs.mollie.com/reference/v2/refunds-api/list-payment-refunds
		 * @param string $transactionId
		 * @return array
		 */
		public function listRefunds(string $transactionId): array {
			return $this->callHttpClient("GET", "payments/{$transactionId}/refunds");
		}
		
		/**
		 * Returns true if Mollie is in test mode, false if not
		 * @return bool
		 */
		public function testMode(): bool {
			return $this->testMode;
		}
		
		/**
		 * Resolves the refund amount and currency from a RefundRequest.
		 * If amount is set, converts from minor units to major units as required by the Mollie API.
		 * If amount is null, fetches the remaining refundable amount from the payment for a full refund.
		 * @param string $transactionId
		 * @param int|null $amount
		 * @param string $currency
		 * @return array
		 */
		protected function resolveRefundAmount(string $transactionId, ?int $amount, string $currency): array {
			// Amount is set — convert from minor units (e.g. 1050) to major units (e.g. "10.50")
			if ($amount !== null) {
				return [
					'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
					'response' => [
						'amount'   => number_format($amount / 100, 2, '.', ''),
						'currency' => $currency,
					],
				];
			}
			
			// Amount is null — full refund requested. Fetch the remaining refundable amount from Mollie.
			$payment = $this->getPaymentInfo($transactionId);
			
			if ($payment["request"]["result"] == 0) {
				return $payment;
			}
			
			// amountRemaining is the portion of the payment not yet refunded.
			// Already in major units — no conversion needed.
			$resolvedAmount = $payment["response"]["amountRemaining"]["value"] ?? null;
			$resolvedCurrency = $payment["response"]["amountRemaining"]["currency"] ?? $currency;
			
			// amountRemaining may be absent or "0.00" when there is nothing left to refund
			if ($resolvedAmount === null || $resolvedAmount === '0.00') {
				return ['request' => ['result' => 0, 'errorId' => 500, 'errorMessage' => 'Payment has no refundable amount']];
			}
			
			// Return value
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'amount'   => $resolvedAmount,
					'currency' => $resolvedCurrency,
				],
			];
		}
		
		/**
		 * Returns true if the value is not null, false otherwise.
		 * Used as an array_filter callback to strip unset optional fields before sending to Mollie.
		 * @param mixed $value
		 * @return bool
		 */
		protected function notNull(mixed $value): bool {
			return $value !== null;
		}
	}