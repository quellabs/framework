<?php
	
	namespace Quellabs\Payments\RaboSmartPay;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentInitiationException;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Payments\Contracts\RefundResult;
	
	class Driver implements PaymentProviderInterface {
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var RaboSmartPayGateway|null
		 */
		private ?RaboSmartPayGateway $gateway = null;
		
		/**
		 * Maps our internal module names to Rabo Smart Pay payment brand strings.
		 *
		 * PayPal is listed as supported in older docs but availability depends on your
		 * merchant contract — verify in the Rabo Smart Pay dashboard.
		 *
		 * CARDS bundles all card methods (Mastercard, Visa, Bancontact, Maestro, V PAY)
		 * and is the recommended choice when you want to offer all card types.
		 *
		 * @see https://github.com/rabobank-nederland/omnikassa-sdk-doc (Field description: paymentBrand)
		 */
		private const MODULE_BRAND_MAP = [
			'rabo_ideal'      => 'IDEAL',
			'rabo_bancontact' => 'BANCONTACT',
			'rabo_mastercard' => 'MASTERCARD',
			'rabo_visa'       => 'VISA',
			'rabo_maestro'    => 'MAESTRO',
			'rabo_vpay'       => 'V_PAY',
			'rabo_cards'      => 'CARDS',       // All card methods combined
			'rabo_applepay'   => 'APPLE_PAY',
			'rabo_paypal'     => 'PAYPAL',
		];
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'modules' => array_keys(self::MODULE_BRAND_MAP)
			];
		}
		
		/**
		 * Returns the active configuration for this provider instance.
		 * Merges stored config over the defaults so only explicitly set keys override.
		 * @return array
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this provider instance.
		 * Called by the discovery system after instantiation, before any other methods are invoked.
		 * @param array $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this provider.
		 * Merged with loaded config files during discovery — values from config files take precedence.
		 * @return array
		 */
		public function getDefaults(): array {
			return [
				'test_mode'         => false,
				'refresh_token'     => '',   // Long-lived token from the Rabo Smart Pay dashboard
				'signing_key'       => '',   // Base64-encoded signing key from the dashboard
				'return_url'        => '',
				'cancel_return_url' => '',
				'default_currency'  => 'EUR',
				'language'          => 'NL',
				'skip_result_page'  => true,
			];
		}
		
		/**
		 * Returns the normalized issuer list for the given payment module.
		 *
		 * iDEAL 2.0 removed direct issuer (bank) pre-selection. Under iDEAL 2.0,
		 * bank selection always happens on the Rabo Smart Pay hosted checkout page.
		 * All other payment brands are also selected on the hosted page.
		 *
		 * This method always returns an empty array for all payment modules.
		 *
		 * @param string $paymentModule e.g. 'rabo_ideal'
		 * @return array Always empty — Rabo Smart Pay handles payment method UI on the hosted page
		 */
		public function getPaymentOptions(string $paymentModule): array {
			// iDEAL 2.0 removed direct bank pre-selection (was available in OmniKassa 1.x via issuerId).
			// All payment method and bank selection is now handled on the hosted checkout page.
			return [];
		}
		
		/**
		 * Initiates a payment by announcing an order at Rabo Smart Pay.
		 *
		 * The flow:
		 *   1. Exchange the refresh token for a short-lived access token (handled by the gateway).
		 *   2. POST the order announcement with payment details.
		 *   3. Receive a redirectUrl pointing to the Rabo Smart Pay hosted checkout.
		 *   4. Redirect the shopper to that URL.
		 *
		 * After payment, Rabo Smart Pay redirects the shopper back to merchantReturnURL
		 * with ?order_id={merchantOrderId}&status={orderStatus} appended.
		 *
		 * The omnikassaOrderId in the response is the UUID for all subsequent status
		 * and refund calls — distinct from our internal merchantOrderId.
		 *
		 * @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$config = $this->getConfig();
			
			// Resolve the Rabo Smart Pay payment brand string from the module name.
			if (!isset(self::MODULE_BRAND_MAP[$request->paymentModule])) {
				throw new PaymentInitiationException('rabosmartpay', 0, "Unknown payment module: '{$request->paymentModule}'");
			}
			
			// Convert module to brand
			$paymentBrand = self::MODULE_BRAND_MAP[$request->paymentModule];
			
			// Generate a unique merchant order ID. This is returned in the return URL as
			// ?order_id= and in the Status Pull response, so the application must store it
			// from InitiateResult metadata to correlate those callbacks to this payment.
			$merchantOrderId = uniqid('order', more_entropy: true);

			// Build the order announcement payload.
			// amount.amount is in euro cents (minor units), passed as an integer string.
			// Rabo Smart Pay only supports EUR; other currencies are rejected.
			$payload = [
				'merchantOrderId'   => $merchantOrderId,
				'description'       => mb_substr($request->description, 0, 35), // API limit: 35 chars
				'amount'            => [
					'currency' => $request->currency ?: $config['default_currency'],
					'amount'   => (string)$request->amount, // In cents, as a string
				],
				'language'          => $config['language'],
				'merchantReturnURL' => $config['return_url'],
				'skipHppResultPage' => (bool)$config['skip_result_page'],
				'paymentBrand'      => $paymentBrand,
				
				// FORCE_ONCE skips brand selection on the hosted page for this order only.
				// Use FORCE_ALWAYS when you never want brand selection shown.
				'paymentBrandForce' => 'FORCE_ONCE',
			];
			
			// Attach customer information when billing address data is available.
			// Rabo Smart Pay uses this to pre-fill the hosted page and for risk scoring.
			if ($request->billingAddress !== null) {
				// Add billing information
				$customerInfo = array_filter([
					'emailAddress' => $request->billingAddress->email ?: null,
					'fullName'     => trim(implode(' ', array_filter([
						$request->billingAddress->givenName,
						$request->billingAddress->familyName,
					]))) ?: null,
					'telephoneNumber' => $request->billingAddress->phone ?: null,
				], fn($v) => $v !== null);
				
				// Add customer information
				if (!empty($customerInfo)) {
					$payload['customerInformation'] = $customerInfo;
				}

				// Attach billing address when full address fields are present.
				$billingDetail = array_filter([
					'firstName'           => $request->billingAddress->givenName ?: null,
					'lastName'            => $request->billingAddress->familyName ?: null,
					'street'              => $request->billingAddress->street ?: null,
					'houseNumber'         => $request->billingAddress->houseNumber ?: null,
					'houseNumberAddition' => $request->billingAddress->houseNumberSuffix ?: null,
					'postalCode'          => $request->billingAddress->postalCode ?: null,
					'city'                => $request->billingAddress->city ?: null,
					'countryCode'         => $request->billingAddress->country ?: null,
				], fn($v) => $v !== null);

				if (!empty($billingDetail)) {
					$payload['billingDetail'] = $billingDetail;
				}
			}

			// Attach shipping address when available.
			if ($request->shippingAddress !== null) {
				$shippingDetail = array_filter([
					'firstName'           => $request->shippingAddress->givenName ?: null,
					'lastName'            => $request->shippingAddress->familyName ?: null,
					'street'              => $request->shippingAddress->street ?: null,
					'houseNumber'         => $request->shippingAddress->houseNumber ?: null,
					'houseNumberAddition' => $request->shippingAddress->houseNumberSuffix ?: null,
					'postalCode'          => $request->shippingAddress->postalCode ?: null,
					'city'                => $request->shippingAddress->city ?: null,
					'countryCode'         => $request->shippingAddress->country ?: null,
				], fn($v) => $v !== null);

				if (!empty($shippingDetail)) {
					$payload['shippingDetail'] = $shippingDetail;
				}
			}
			
			// Announce the order and retrieve the redirect URL.
			$result = $this->getGateway()->announceOrder($payload);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(
					'rabosmartpay',
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Grab the API response
			$data = $result['response'];
			
			// Validate the existence of omnikassaOrderId
			if (empty($data['omnikassaOrderId'])) {
				throw new PaymentInitiationException('rabosmartpay', 0, 'Order announce returned no omnikassaOrderId');
			}

			// Validate the existence of redirectUrl
			if (empty($data['redirectUrl'])) {
				throw new PaymentInitiationException('rabosmartpay', 0, 'Order announce returned no redirectUrl');
			}
			
			return new InitiateResult(
				provider: 'rabosmartpay',
				transactionId: $data['omnikassaOrderId'],
				redirectUrl: $data['redirectUrl'],
				metadata: [
					'orderReference' => $merchantOrderId,
				],
			);
		}
		
		/**
		 * Performs the Status Pull loop using a webhook notification token and emits
		 * one PaymentState per order result via the provided callback.
		 * Handles moreOrderResultsAvailable pagination internally.
		 *
		 * @param string   $notificationToken The authentication token from the webhook notification body
		 * @param callable $emit              Called with each resolved PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchangeFromNotification(string $notificationToken, callable $emit): void {
			$moreResultsAvailable = true;

			while ($moreResultsAvailable) {
				$result = $this->getGateway()->pullOrderStatuses($notificationToken);

				if ($result['request']['result'] === 0) {
					throw new PaymentExchangeException(
						'rabosmartpay',
						$result['request']['errorId'],
						$result['request']['errorMessage']
					);
				}

				$pullData = $result['response'];
				$moreResultsAvailable = (bool)($pullData['moreOrderResultsAvailable'] ?? false);

				foreach ($pullData['orderResults'] ?? [] as $orderResult) {
					$omnikassaOrderId = $orderResult['omnikassaOrderId'] ?? '';

					if (empty($omnikassaOrderId)) {
						continue;
					}

					$emit($this->exchange($omnikassaOrderId, $orderResult));
				}
			}
		}

		/**
		 * Resolves a single order result into a PaymentState.
		 * Called from both exchange() (return URL) and exchangeFromNotification() (webhook).
		 *
		 * @param string $transactionId omnikassaOrderId UUID or merchantOrderId (return URL flow)
		 * @param array  $extraData     Order result data from Status Pull or return URL extraData
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// The orderStatus field is the authoritative source of truth.
			$orderStatus = strtoupper($extraData['orderStatus'] ?? '');
			
			// If status is absent, fall back to a direct API call.
			// This should only happen if the return URL is visited without parameters.
			if (empty($orderStatus)) {
				$result = $this->getGateway()->getOrderStatus($transactionId);
				
				if ($result['request']['result'] === 0) {
					throw new PaymentExchangeException(
						'rabosmartpay',
						$result['request']['errorId'],
						$result['request']['errorMessage']
					);
				}
				
				$extraData = array_merge($extraData, $result['response']);
				$orderStatus = strtoupper($extraData['orderStatus'] ?? 'IN_PROGRESS');
			}
			
			$state = match ($orderStatus) {
				'COMPLETED'   => PaymentStatus::Paid,
				'CANCELLED'   => PaymentStatus::Canceled,
				'EXPIRED'     => PaymentStatus::Expired,
				'FAILURE'     => PaymentStatus::Failed,
				'IN_PROGRESS' => PaymentStatus::Pending,
				default       => PaymentStatus::Pending,
			};
			
			$paidAmount = (int)($extraData['paidAmount']['amount'] ?? 0);
			$currency   = $extraData['paidAmount']['currency'] ?? ($extraData['totalAmount']['currency'] ?? '');
			$valuePaid   = ($state === PaymentStatus::Paid) ? $paidAmount : 0;
			
			$paymentBrand = null;
			foreach ($extraData['transactions'] ?? [] as $transaction) {
				if (strtoupper($transaction['type'] ?? '') === 'PAYMENT' &&
					strtoupper($transaction['status'] ?? '') === 'SUCCESS') {
					$paymentBrand = $transaction['paymentBrand'] ?? null;
					break;
				}
			}
			
			return new PaymentState(
				provider: 'rabosmartpay',
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: 0,
				internalState: $orderStatus,
				metadata: array_filter([
					'paymentBrand' => $paymentBrand,
					'errorCode'    => $extraData['errorCode'] ?? null,
				], fn($v) => $v !== null && $v !== ''),
			);
		}
		
		/**
		 * Refunds a previously completed payment.
		 *
		 * Rabo Smart Pay processes refunds asynchronously. A COMPLETED order is required —
		 * attempting to refund an IN_PROGRESS or CANCELLED order will be rejected.
		 *
		 * Full refund: omit amount from the payload.
		 * Partial refund: include amount.amount in euro cents and amount.currency.
		 *
		 * The refund response contains a refundId that can be used to retrieve refund status.
		 *
		 * @see https://docs.developer.rabobank.com/smartpay/reference/create-refund
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			$config = $this->getConfig();
			
			// Build the refund payload.
			// Omitting 'amount' triggers a full refund.
			// Including it with an amount in cents triggers a partial refund.
			$payload = array_filter([
				'description' => $request->description ?? '',
				'amount'      => $request->amount !== null ? [
					'currency' => $request->currency ?: $config['default_currency'],
					'amount'   => (string)$request->amount, // In cents, as a string
				] : null,
			], fn($v) => $v !== null && $v !== '');
			
			$result = $this->getGateway()->refundOrder($request->paymentReference, $payload);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(
					'rabosmartpay',
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// The refund response contains an id field identifying this specific refund operation.
			$refundId = (string)($result['response']['id'] ?? $request->paymentReference);
			
			return new RefundResult(
				provider: 'rabosmartpay',
				paymentReference: $request->paymentReference,
				refundId: $refundId,
				
				// For full refunds, amount is null — pass 0 as the value since the actual
				// refunded amount is unknown until the webhook confirms it.
				value: $request->amount ?? 0,
				currency: $request->currency,
			);
		}
		
		/**
		 * Returns refund records for a previously completed payment.
		 *
		 * Rabo Smart Pay does not embed refund data in the order status response.
		 * This method uses the dedicated refund details endpoint to retrieve refund records.
		 *
		 * @see https://docs.developer.rabobank.com/smartpay/reference/get-refund-details
		 * @param string $paymentReference The omnikassaOrderId (Rabo Smart Pay UUID)
		 * @return RefundResult[]
		 * @throws PaymentExchangeException
		 */
		public function getRefunds(string $paymentReference): array {
			// Rabo Smart Pay does not currently offer a list-all-refunds endpoint per order
			// in the standard Online Payment API. Return an empty array and let callers
			// track refunds via the refund() return value or the webhook exchange.
			// This can be expanded when the Merchant Services API is integrated.
			return [];
		}
		
		/**
		 * Verifies the HMAC-SHA512 signature on a webhook notification or return URL.
		 * Delegates to the gateway for the actual cryptographic check.
		 *
		 * @param string $payload Comma-joined field values exactly as documented
		 * @param string $providedSignature Hex-encoded signature from the notification or URL
		 * @return bool True when the signature is valid
		 */
		public function verifySignature(string $payload, string $providedSignature): bool {
			$config = $this->getConfig();
			
			// If no signing key is configured, skip verification.
			// This allows local development without a dashboard key, but should never
			// occur in production — log a warning so the gap doesn't go unnoticed.
			if (empty($config['signing_key'])) {
				error_log('Rabo Smart Pay: verifySignature called with no signing_key configured — skipping verification');
				return true;
			}
			
			return $this->getGateway()->verifySignature($payload, $providedSignature, $config['signing_key']);
		}
		
		/**
		 * Lazily instantiates and returns the RaboSmartPayGateway.
		 * Construction is deferred until first use so that config is guaranteed to be set.
		 * @return RaboSmartPayGateway
		 */
		private function getGateway(): RaboSmartPayGateway {
			return $this->gateway ??= new RaboSmartPayGateway($this);
		}
	}