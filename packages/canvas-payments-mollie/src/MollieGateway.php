<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Quellabs\Payments\Contracts\PaymentAddress;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\RefundRequest;
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
		public function __construct(ConfigProviderInterface $configProvider) {
			$configData = $configProvider->loadConfigFile('mollie');
			$this->apiKey = $configData->get("api_key", "");
			$this->testMode = $configData->getAs("test_mode", "bool", false);
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
			
			// List responses are wrapped in _embedded — unwrap the methods collection
			if (!empty($jsonData['_embedded'])) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $jsonData['_embedded']['methods']];
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
		 * @param RefundRequest $refundRequest
		 * @return array
		 */
		public function createRefund(RefundRequest $refundRequest): array {
			// Transaction cannot be empty
			if (empty($refundRequest->transactionId)) {
				return ['request' => ['result' => 0, 'errorId' => 500, 'errorMessage' => 'Missing transactionId']];
			}
			
			// Value to refund cannot be 0
			if ($refundRequest->amount === 0) {
				return ['request' => ['result' => 0, 'errorId' => 500, 'errorMessage' => 'Invalid refund value']];
			}
			
			// issue refund to mollie
			$value = $refundRequest->amount;
			$currencyType = $refundRequest->currency;
			$description = $refundRequest->description;
			$transactionId = $refundRequest->transactionId;
			
			return $this->callHttpClient("POST", "payments/{$transactionId}/refunds", [
				"amount"      => [
					"currency" => $currencyType,
					"value"    => number_format($value / 100, 2, '.', '')
				],
				"description" => $description,
				"testmode"    => $this->testMode
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
		 * @param PaymentRequest $request
		 * @param string $paymentMethod
		 * @return array
		 */
		public function createPayment(PaymentRequest $request, string $paymentMethod): array {
			$mollieData = array_filter([
				'amount'          => [
					'currency' => $request->currency,
					'value'    => number_format($request->amount / 100, 2, '.', ''),
				],
				'description'     => $request->description,
				'redirectUrl'     => $request->redirectUrl,
				'cancelUrl'       => $request->cancelUrl,
				'webhookUrl'      => $request->webhookUrl,
				'metadata'        => $request->metadata,
				'method'          => !empty($paymentMethod) ? $paymentMethod : null,
				'issuer'          => !empty($request->issuerId) ? $request->issuerId : null,
				'billingAddress'  => $request->billingAddress !== null ? $this->serializeAddress($request->billingAddress) : null,
				'shippingAddress' => $request->shippingAddress !== null ? $this->serializeAddress($request->shippingAddress) : null,
				'testmode'        => $this->testMode(),
			], [$this, 'notNull']);
			
			return $this->callHttpClient('POST', 'payments', $mollieData);
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
		 * Serializes a PaymentAddress into the array shape Mollie expects
		 * @param PaymentAddress $address
		 * @return array
		 */
		protected function serializeAddress(PaymentAddress $address): array {
			return array_filter([
				'title'            => $address->title,
				'givenName'        => $address->givenName,
				'familyName'       => $address->familyName,
				'organizationName' => $address->organizationName,
				'streetAndNumber'  => $address->streetAndNumber,
				'streetAdditional' => $address->streetAdditional,
				'postalCode'       => $address->postalCode,
				'city'             => $address->city,
				'region'           => $address->region,
				'country'          => $address->country,
				'email'            => $address->email,
				'phone'            => $address->phone,
			], [$this, 'notNull']);
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