<?php
	
	namespace Quellabs\Payments\Adyen;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentAddress;
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
		private array $config;
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var AdyenGateway|null
		 */
		private ?AdyenGateway $gateway = null;
		
		/**
		 * Maps our internal module names (e.g. 'adyen_ideal') to Adyen's payment method type
		 * strings as used in /paymentMethods and /payments requests.
		 * @see https://docs.adyen.com/payment-methods/
		 */
		private const MODULE_TYPE_MAP = [
			'adyen_ideal'      => 'ideal',
			'adyen_creditcard' => 'scheme',
			'adyen_bancontact' => 'bcmc',
			'adyen_sofort'     => 'directEbanking',
			'adyen_giropay'    => 'giropay',
			'adyen_klarna'     => 'klarna',
			'adyen_applepay'   => 'applepay',
			'adyen_googlepay'  => 'googlepay',
			'adyen_paypal'     => 'paypal',
		];
		
		/**
		 * Payment modules that require full address and shopper identity data.
		 * Used for AVS (address verification) on card payments, and mandatory for all BNPL
		 * methods. Sending this data to other methods adds noise to risk signals and may
		 * hurt acceptance rates.
		 * @see https://docs.adyen.com/payment-methods/
		 */
		private const MODULES_REQUIRING_ADDRESS_DATA = [
			'adyen_creditcard',  // AVS check
			'adyen_klarna',      // Required: address, name, email, phone
			'adyen_in3',         // Required: address, name, email, phone
			'adyen_riverty',     // Required: address, name, email, phone
			'adyen_billie',      // Required: address, name, email, phone, companyName
		];
		
		/**
		 * Payment modules that benefit from shopperEmail even without full address data.
		 * Email is low-risk and triggers auto-sent payment instructions for bank transfers.
		 * Methods in MODULES_REQUIRING_ADDRESS_DATA implicitly include email — this list
		 * covers the remaining methods where only email is useful.
		 * @see https://docs.adyen.com/payment-methods/bank-transfer
		 */
		private const MODULES_REQUIRING_SHOPPER_EMAIL = [
			'adyen_bancontact',
			'adyen_sofort',
			'adyen_giropay',
		];
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => 'adyen',
				'modules' => array_keys(self::MODULE_TYPE_MAP),
			];
		}
		
		/**
		 * Returns the active configuration for this provider instance.
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
				'test_mode'            => false,
				'api_key'              => '',
				'merchant_account'     => '',
				'hmac_key'             => '',
				'return_url'           => '',
				'cancel_return_url'    => '',
				'webhook_url'          => '',
				'live_endpoint_prefix' => '',
				
				// Used by getPaymentOptions() to filter the /paymentMethods response.
				// Adyen returns a country-specific method list (e.g. iDEAL only for NL).
				'default_country'      => 'NL',
				'default_currency'     => 'EUR',
			];
		}
		
		/**
		 * Returns the normalised issuer list for the given payment module.
		 *
		 * This driver uses Pay by Link (/paymentLinks), which has no issuer field —
		 * there is no mechanism to pass a pre-selected bank to the hosted payment page.
		 * Additionally, iDEAL issuer pre-selection was discontinued with iDEAL 2.0 (01-04-2025).
		 * This method always returns an empty array.
		 *
		 * @param string $paymentModule e.g. 'adyen_ideal'
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Initiates a payment using Adyen Pay by Link.
		 * Creates a hosted payment page via POST /paymentLinks and returns its URL.
		 * The shopper is redirected to an Adyen-hosted page to complete payment,
		 * then returned to return_url with ?redirectResult= appended.
		 * @see https://docs.adyen.com/unified-commerce/pay-by-link/create-payment-links/api
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$config = $this->getConfig();
			$billing = $request->billingAddress;
			$module = $request->paymentModule;
			$useAddressData = in_array($module, self::MODULES_REQUIRING_ADDRESS_DATA, true);
			$useShopperEmail = $useAddressData || in_array($module, self::MODULES_REQUIRING_SHOPPER_EMAIL, true);
			
			// Build shopperName — only when address data is relevant.
			// Required by all BNPL methods; must have at least one component to be worth sending.
			$shopperName = null;
			
			if ($useAddressData && $billing !== null && ($billing->givenName !== null || $billing->familyName !== null)) {
				$shopperName = array_filter([
					'firstName' => $billing->givenName,
					'lastName'  => $billing->familyName,
				], fn($v) => $v !== null);
			}
			
			// Build the session payload. Amount is already in minor units (e.g. 1250 = €12.50).
			$payload = array_filter([
				'merchantAccount' => $config['merchant_account'],
				'amount'          => [
					'value'    => $request->amount,
					'currency' => $request->currency,
				],
				'reference'       => $request->reference,
				'returnUrl'       => $config['return_url'],
				'description'     => $request->description,
				'shopperLocale'   => 'nl-NL',
				'countryCode'     => $billing?->country ?: null,
				'shopperEmail'    => $useShopperEmail ? ($billing?->email ?: null) : null,
				'telephoneNumber' => $useAddressData ? ($billing?->phone ?: null) : null,
				'shopperName'     => $shopperName ?: null,
				'billingAddress'  => $useAddressData && $billing !== null ? $this->buildAddressPayload($billing) : null,
				'deliveryAddress' => $useAddressData && $request->shippingAddress !== null ? $this->buildAddressPayload($request->shippingAddress) : null,
				'companyName'     => $useAddressData ? ($billing?->organizationName ?: null) : null,
			], fn($v) => $v !== null && $v !== '' && $v !== []);
			
			// Create a new payment link
			$result = $this->getGateway()->createPaymentLink($payload);
			
			// If that failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Grab the response
			$response = $result['response'];
			
			// Return the result
			return new InitiateResult(
				provider: self::getMetadata()['driver'],
				transactionId: $response['id'],
				redirectUrl: $response['url'],
			);
		}
		
		/**
		 * Dispatches a payment event to the appropriate handler based on action.
		 *
		 * For action='return': delegates to buildStateFromReturn(), which submits the
		 * redirectResult to POST /payments/details to resolve the final result code.
		 *
		 * For action='webhook': delegates to buildStateFromWebhook(), which maps the
		 * NotificationRequestItem directly — no API call needed.
		 *
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/payments/details
		 * @param string $transactionId The Adyen sessionId (return flow) or pspReference (webhook flow)
		 * @param array $extraData
		 *   - action: 'return' | 'webhook'
		 *   - redirectResult: (string, required for action='return') URL-decoded redirectResult query param
		 *   - paymentData: (string|null, optional for action='return') paymentData from session if available
		 *   - notification: (array, required for action='webhook') decoded NotificationRequestItem
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			$action = $extraData['action'] ?? 'return';
			
			if ($action === 'webhook') {
				return $this->buildStateFromWebhook($transactionId, $extraData['notification'] ?? []);
			} else {
				return $this->buildStateFromReturn($transactionId, $extraData);
			}
		}
		
		/**
		 * Resolves the final payment state after the shopper returns from the hosted payment page.
		 * Submits the redirectResult to POST /payments/details and maps the resultCode to a PaymentState.
		 *
		 * redirectResult is appended by Adyen to the returnUrl after a redirect-based payment
		 * (e.g. iDEAL, 3DS). For inline completions (card without redirect) the Drop-in handles
		 * this call itself and the shopper never hits the return URL.
		 *
		 * paymentData is optional context from the session — include it when available, Adyen
		 * uses it to correlate the details call back to the original session.
		 *
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/payments/details
		 * @see https://docs.adyen.com/online-payments/payment-result-codes/
		 * @param string $transactionId The Adyen sessionId from the return URL query string
		 * @param array $extraData
		 *   - redirectResult: (string, required) URL-decoded redirectResult query param
		 *   - paymentData: (string|null, optional) paymentData from session if available
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		private function buildStateFromReturn(string $transactionId, array $extraData): PaymentState {
			// Fetch redirectResult
			$redirectResult = $extraData['redirectResult'] ?? null;
			
			// redirectResult is mandatory — without it there is nothing to submit to /payments/details
			if (empty($redirectResult)) {
				throw new PaymentExchangeException(self::getMetadata()['driver'], 0, "Missing 'redirectResult' in extraData for action='return'.");
			}
			
			// Build the /payments/details payload. paymentData is optional but should be included
			// when available — array_filter drops it cleanly when null.
			$payload = array_filter([
				'details'     => ['redirectResult' => $redirectResult],
				'paymentData' => $extraData['paymentData'] ?? null,
			], fn($v) => $v !== null);
			
			// Submit to Adyen — this resolves the pending redirect into a final resultCode
			$result = $this->getGateway()->getPaymentDetails($payload);
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::getMetadata()['driver'], $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Extract the fields needed to build a PaymentState
			$response = $result['response'];
			$resultCode = $response['resultCode'] ?? 'Unknown';
			$pspReference = $response['pspReference'] ?? null;
			$currency = $response['amount']['currency'] ?? '';
			$valuePaid = $response['amount']['value'] ?? 0;
			
			// Map Adyen's resultCode to our internal PaymentStatus.
			// resultCode is the canonical status field for synchronous /payments/details responses.
			$state = match ($resultCode) {
				// Payment authorized — funds will be collected (auto-capture) or reserved (manual).
				'Authorised' => PaymentStatus::Paid,
				
				// Shopper abandoned or explicitly canceled before completing.
				'Cancelled' => PaymentStatus::Canceled,
				
				// Issuer or Adyen risk engine declined the payment.
				'Refused', 'Error' => PaymentStatus::Failed,
				
				// Async methods (bank transfer, voucher) — final state arrives via webhook.
				'Pending', 'Received' => PaymentStatus::Pending,
				
				// presentToShopper / identifyShopper / challengeShopper should not reach the
				// return URL in the Sessions flow, but treat as pending to avoid data loss.
				default => PaymentStatus::Pending,
			};
			
			return new PaymentState(
				provider: self::getMetadata()['driver'],
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				// Only record a paid amount when the payment is actually authorized
				valuePaid: $state === PaymentStatus::Paid ? (int)$valuePaid : 0,
				valueRefunded: 0,
				internalState: $resultCode,
				metadata: array_filter([
					// pspReference is required for future captures and refunds
					'paymentReference' => $pspReference,
					'paymentMethod'    => $response['paymentMethod']['type'] ?? null,
					'refusalReason'    => $response['refusalReason'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Refunds a previously captured payment.
		 * For a full refund pass $request->amount === $originalAmount.
		 * Adyen handles partial refunds with the same endpoint — the difference is solely the amount.
		 *
		 * IMPORTANT: $request->paymentReference must be the Adyen pspReference of the original
		 * AUTHORISATION, not the sessionId. The pspReference is available as
		 * PaymentState::$metadata['paymentReference'] after a successful payment exchange — persist
		 * it when handling the payment_exchange signal and pass it here as captureId.
		 *
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/payments/{paymentPspReference}/refunds
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// captureId must be the pspReference from the original AUTHORISATION.
			// Adyen's refund endpoint uses it as the URL path parameter to identify the payment.
			$paymentReference = $request->paymentReference;
			
			// If none given, throw error
			if (empty($paymentReference)) {
				throw new PaymentRefundException(
					self::getMetadata()['driver'],
					0,
					"Cannot refund: paymentReference is empty. " .
					"Pass the Adyen pspReference (PaymentState::\$metadata['paymentReference']) as captureId."
				);
			}
			
			// Call the API to initiate the refund
			$result = $this->getGateway()->refundPayment(
				$paymentReference,
				$request->amount,
				$request->currency,
				$request->description ?? ''
			);
			
			// If that failed throw an error
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::getMetadata()['driver'], $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Adyen returns status='received' immediately; the actual outcome arrives via REFUND webhook.
			// Adyen assigns the refund its own pspReference, distinct from the original payment's.
			// We store it as refundId so callers can correlate the incoming REFUND webhook.
			return new RefundResult(
				provider: self::getMetadata()['driver'],
				paymentReference: $paymentReference,
				refundId: $result['response']['pspReference'],
				value: $request->amount,
				currency: $request->currency,
			);
		}
		
		/**
		 * Returns a list of RefundResult objects for all refunds associated with a transaction.
		 * Adyen does not provide a search-by-transaction API in the Sessions flow.
		 * Refund history must be reconstructed from webhook events stored by the application.
		 * This method is intentionally not implemented here — return an empty array as a safe default.
		 * @param string $paymentReference
		 * @return RefundResult[]
		 */
		public function getRefunds(string $paymentReference): array {
			return [];
		}
		
		/**
		 * Verifies the HMAC signature on an incoming Adyen webhook notification.
		 * Delegates to the gateway which uses hash_equals for timing-safe comparison.
		 * @param array $notification The decoded NotificationRequestItem from the webhook body
		 * @return bool
		 */
		public function verifyWebhookSignature(array $notification): bool {
			return $this->getGateway()->verifyHmacSignature($notification, $this->getConfig()['hmac_key']);
		}
		
		/**
		 * Lazily instantiated Adyen gateway.
		 * @return AdyenGateway
		 */
		private function getGateway(): AdyenGateway {
			return $this->gateway ??= new AdyenGateway($this);
		}
		
		/**
		 * Maps an Adyen webhook NotificationRequestItem to a PaymentState.
		 * Webhooks use eventCode + success to determine the outcome, not resultCode.
		 * The pspReference in AUTHORISATION is the capture ID required for future refunds.
		 * @see https://docs.adyen.com/development-resources/webhooks/webhook-types
		 * @param string $transactionId The sessionId or pspReference from the notification
		 * @param array $notification The decoded NotificationRequestItem
		 * @return PaymentState
		 */
		private function buildStateFromWebhook(string $transactionId, array $notification): PaymentState {
			$eventCode = $notification['eventCode'] ?? '';
			$success = ($notification['success'] ?? '') === 'true';
			$pspReference = $notification['pspReference'] ?? null;
			$currency = $notification['amount']['currency'] ?? '';
			$value = (int)($notification['amount']['value'] ?? 0);
			
			// Convert adyen raw state to PaymentStatus
			$state = match (true) {
				$eventCode === 'AUTHORISATION' && $success => PaymentStatus::Paid,
				$eventCode === 'AUTHORISATION' && !$success => PaymentStatus::Failed,
				$eventCode === 'CANCELLATION' && $success => PaymentStatus::Canceled,
				$eventCode === 'REFUND' && $success => PaymentStatus::Refunded,
				
				// CAPTURE and CAPTURE_FAILED are only relevant when using manual capture.
				// In the default Sessions flow with auto-capture, AUTHORISATION is the terminal event.
				$eventCode === 'CAPTURE' && $success => PaymentStatus::Paid,
				$eventCode === 'CAPTURE_FAILED' => PaymentStatus::Failed,
				default => PaymentStatus::Pending,
			};
			
			
			// For REFUND webhooks the amount field is the refunded amount, not the original paid amount.
			// valuePaid is unknown — use null to signal that the caller should preserve the original
			// paid amount from their stored AUTHORISATION state rather than overwrite it.
			$valueRefunded = ($eventCode === 'REFUND' && $success) ? $value : 0;
			
			// Set valuePaid to null (unknown) on refund. Adyen does not pass paid value
			$valuePaid = match (true) {
				$eventCode === 'REFUND' => null,
				$state === PaymentStatus::Paid => $value,
				default => 0,
			};
			
			return new PaymentState(
				provider: self::getMetadata()['driver'],
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $valueRefunded,
				internalState: $eventCode,
				metadata: array_filter([
					'paymentReference'  => $pspReference,
					'merchantReference' => $notification['merchantReference'] ?? null,
					'paymentMethod'     => $notification['paymentMethod'] ?? null,
					'reason'            => $notification['reason'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Maps a PaymentAddress to Adyen's billingAddress / deliveryAddress shape.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/paymentLinks#request-billingAddress
		 * @param PaymentAddress $address
		 * @return array
		 */
		private function buildAddressPayload(PaymentAddress $address): array {
			$houseNumberOrName = $address->houseNumber;
			
			if (!empty($address->houseNumberSuffix)) {
				$houseNumberOrName .= ' ' . $address->houseNumberSuffix;
			}
			
			return [
				'street'            => $address->street,
				'houseNumberOrName' => $houseNumberOrName,
				'postalCode'        => $address->postalCode,
				'city'              => $address->city,
				'country'           => $address->country,
				'stateOrProvince'   => $address->region ?? 'N/A',
			];
		}
		
	}