<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Canvas\Kernel;
	use Quellabs\Contracts\Payment\PaymentAddress;
	use Quellabs\Contracts\Payment\PaymentRequest;
	use Quellabs\Contracts\Payment\RefundRequest;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
	
	class MollieGateway {
		
		protected string $apiKey;
		protected string $apiUserAgent;
		protected bool $testMode;
		protected array $curlVersion;
		
		/**
		 * Mollie constructor.
		 */
		public function __construct(Kernel $kernel) {
			$configData = $kernel->loadConfigFile('mollie');
			$this->apiKey = $configData->get("api_key", "");
			$this->curlVersion = curl_version();
			$this->testMode = $configData->getAs("test_mode", "bool", false);
			
			$this->apiUserAgent = join(" ", [
				"Mollie/1.1.0",
				"PHP/" . phpversion(),
				"cURL/" . $this->curlVersion["version"],
				$this->curlVersion["ssl_version"],
			]);
		}
		
		/**
		 * Use curl to call mollie
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
						'Accept'               => 'application/json',
						'Authorization'        => "Bearer {$this->apiKey}",
						'User-Agent'           => $this->apiUserAgent,
						'X-Mollie-Client-Info' => php_uname(),
						'Content-Type'         => 'application/json',
					],
					'verify_peer' => true,
					'verify_host' => true,
					'cafile'      => dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'cacert.pem'
				]);
				
				$response = $client->request($method, $action, ['json' => $data]);
				$statusCode = $response->getStatusCode();
				$jsonData = $response->toArray();
			} catch (\Exception|TransportExceptionInterface|DecodingExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
			
			if (isset($jsonData['resource']) && in_array($jsonData['resource'], ['payment', 'method'])) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $jsonData];
			}
			
			if (!empty($jsonData['_embedded'])) {
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $jsonData['_embedded']['methods']];
			}
			
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
		 * Retrieve all captures for a certain payment.
		 * Captures are used for payments that have the authorize-then-capture flow. The only payment methods at
		 * the moment that have this flow are Klarna Pay later and Klarna Slice it.
		 * @url https://docs.mollie.com/reference/v2/captures-api/list-captures
		 * @param string $paymentId
		 * @return array
		 */
		public function listCaptures(string $paymentId): array {
			return $this->callHttpClient("GET", "payments/{$paymentId}/captures");
		}
		
		/**
		 * Retrieve a single capture by its ID. Note the original payment’s ID is needed as well.
		 * Captures are used for payments that have the authorize-then-capture flow. The only payment methods at
		 * the moment that have this flow are Klarna Pay later and Klarna Slice it.
		 * Example: /v2/payments/tr_7UhSN1zuXS/captures/cpt_4qqhO89gsT
		 * @url https://docs.mollie.com/reference/v2/captures-api/get-capture
		 * @param string $paymentId
		 * @param string $captureId
		 * @return array
		 */
		public function getCaptureInfo(string $paymentId, string $captureId): array {
			return $this->callHttpClient("GET", "payments/{$paymentId}/{$captureId}");
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
			if ($refundRequest->amount === 0.0) {
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
					"value"    => number_format($value, 2, '.', '')
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
					'value'    => number_format($request->amount, 2, '.', ''),
				],
				'description'     => $request->description,
				'redirectUrl'     => $request->redirectUrl,
				'cancelUrl'       => $request->cancelUrl,
				'webhookUrl'      => $request->webhookUrl,
				'metadata'        => array_merge(['reference' => $request->reference], $request->metadata),
				'method'          => !empty($paymentMethod) ? $paymentMethod : null,
				'issuer'          => !empty($request->issuerId) ? $request->issuerId : null,
				'billingAddress'  => $request->billingAddress !== null ? $this->serializeAddress($request->billingAddress) : null,
				'shippingAddress' => $request->shippingAddress !== null ? $this->serializeAddress($request->shippingAddress) : null,
				'testmode'        => $this->testMode ?: null,
			], [$this, 'notNull']);
			
			return $this->callHttpClient('POST', 'payments', $mollieData);
		}
		
		/**
		 * An order has an automatically created payment that your customer can use to pay for the order.
		 * When the payment expires you can create a new payment for the order using this endpoint.
		 * A new payment can only be created while the status of the order is created, and when the status of the
		 * existing payment is either expired, canceled or failed.
		 * @url https://docs.mollie.com/reference/v2/orders-api/create-order-payment
		 * @param string $orderId
		 * @param string $paymentMethod
		 * @param int|null $customerId
		 * @param string|null $mandateId
		 * @return array
		 */
		public function createPaymentForOrder(string $orderId, string $paymentMethod, ?int $customerId = null, ?string $mandateId = null): array {
			$mollieData = [
				'method' => $paymentMethod
			];
			
			if (!empty($customerId)) {
				$mollieData["customerId"] = $customerId;
			}
			
			if (!empty($mandateId)) {
				$mollieData["mandateId"] = $mandateId;
			}
			
			return $this->callHttpClient("POST", "orders/{$orderId}/payments", $mollieData);
		}
		
		/**
		 * When using the Orders API, refunds should be made against the Order. When using pay after delivery
		 * payment methods such as Klarna Pay later and Klarna Slice it, this ensures that your customer will
		 * receive credit invoices with the correct product information on them and generally have a great experience.
		 * @url https://docs.mollie.com/reference/v2/orders-api/create-order-refund
		 * @param string $orderId
		 * @param array $data
		 * @return array
		 */
		public function createRefundForOrder(string $orderId, array $data): array {
			return $this->callHttpClient("POST", "orders/{$orderId}/refunds", $data);
		}
		
		/**
		 * Returns information about a mollie order
		 * @url https://docs.mollie.com/reference/v2/orders-api/get-order
		 * @param string $orderId
		 * @return array
		 */
		public function getOrderInfo(string $orderId): array {
			return $this->callHttpClient("GET", "orders/{$orderId}");
		}
		
		/**
		 * This endpoint can be used to update the billing and/or shipping address of an order.
		 * @url https://docs.mollie.com/reference/v2/orders-api/update-order
		 * @param string $orderId
		 * @param array $data
		 * @return array
		 */
		public function updateOrder(string $orderId, array $data): array {
			return $this->callHttpClient("PATCH", "orders/{$orderId}", $data);
		}
		
		/**
		 * Cancels the order. The order can only be canceled when the order doesn’t have any open payments.
		 * @url https://docs.mollie.com/reference/v2/orders-api/cancel-order
		 * @param string $orderId
		 * @return array
		 */
		public function cancelOrder(string $orderId): array {
			return $this->callHttpClient("DELETE", "orders/{$orderId}");
		}
		
		/**
		 * Retrieve all orders. The results are paginated.
		 * @url https://docs.mollie.com/reference/v2/orders-api/list-orders
		 * @return array
		 */
		public function listOrders(): array {
			return $this->callHttpClient("GET", "orders");
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
		 * Retrieve all order refunds. The results are paginated.
		 * @url https://docs.mollie.com/reference/v2/orders-api/list-order-refunds
		 * @param string $orderId
		 * @return array
		 */
		public function listOrderRefunds(string $orderId): array {
			return $this->callHttpClient("GET", "order/{$orderId}/refunds");
		}
		
		/**
		 * Create a mandate for a specific customer. Mandates allow you to charge a customer’s credit card\
		 * or bank account recurrently.
		 * @url https://docs.mollie.com/reference/v2/mandates-api/create-mandate
		 * @param string $customerId
		 * @param string $consumerName
		 * @param string $consumerAccount
		 * @param array $data
		 * @return array
		 */
		public function createMandate(string $customerId, string $consumerName, string $consumerAccount, array $data = []): array {
			return $this->callHttpClient("POST", "customers/{$customerId}/mandates", array_merge($data, [
				"method"          => "directdebit",
				"consumerName"    => $consumerName,
				"consumerAccount" => $consumerAccount
			]));
		}
		
		/**
		 * Retrieve a mandate by its ID and its customer’s ID. The mandate will either contain IBAN or credit
		 * card details, depending on the type of mandate.
		 * @url https://docs.mollie.com/reference/v2/mandates-api/get-mandate
		 * @param int $customerId
		 * @param string $mandateId
		 * @return array
		 */
		public function getMandateInfo(int $customerId, string $mandateId): array {
			return $this->callHttpClient("GET", "customers/{$customerId}/mandates/{$mandateId}");
		}
		
		/**
		 * Revoke a customer’s mandate. You will no longer be able to charge the consumer’s bank account or
		 * credit card with this mandate and all connected subscriptions will be canceled.
		 * @url https://docs.mollie.com/reference/v2/mandates-api/revoke-mandate#
		 * @param int $customerId
		 * @param string $mandateId
		 * @return array
		 */
		public function revokeMandate(int $customerId, string $mandateId): array {
			return $this->callHttpClient("DELETE", "customers/{$customerId}/mandates/{$mandateId}");
		}
		
		/**
		 * Retrieve all mandates for the given customerId, ordered from newest to oldest. The results are paginated.
		 * @url https://docs.mollie.com/reference/v2/mandates-api/list-mandates
		 * @param int $customerId
		 * @return array
		 */
		public function listMandates(int $customerId): array {
			return $this->callHttpClient("GET", "customers/{$customerId}/mandates");
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