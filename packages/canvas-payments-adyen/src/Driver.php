<?php
	
	namespace Quellabs\Payments\Adyen;
	
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
			'adyen_ideal'       => 'ideal',
			'adyen_creditcard'  => 'scheme',
			'adyen_bancontact'  => 'bcmc',
			'adyen_sofort'      => 'directEbanking',
			'adyen_giropay'     => 'giropay',
			'adyen_klarna'      => 'klarna',
			'adyen_applepay'    => 'applepay',
			'adyen_googlepay'   => 'googlepay',
			'adyen_paypal'      => 'paypal',
		];
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'modules' => [
					'adyen_ideal',
					'adyen_creditcard',
					'adyen_bancontact',
					'adyen_sofort',
					'adyen_giropay',
					'adyen_klarna',
					'adyen_applepay',
					'adyen_googlepay',
					'adyen_paypal',
				]
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
				'test_mode'         => false,
				'api_key'           => '',
				'merchant_account'  => '',
				'client_key'        => '',
				'hmac_key'          => '',
				'brand_name'        => '',
				'return_url'        => '',
				'cancel_return_url' => '',
				'webhook_url'       => '',
				
				// Used by getPaymentOptions() to filter the /paymentMethods response.
				// Adyen returns a country-specific method list (e.g. iDEAL only for NL).
				'default_country'   => 'NL',
				'default_currency'  => 'EUR',
			];
		}
		
		/**
		 * Returns the normalized issuer list for the given payment module.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/paymentMethods
		 *
		 * For iDEAL the issuer id is the BIC (e.g. BUNKNL2A), which doubles as the SWIFT code.
		 * Adyen does not include icons in the issuer list — 'icon' is always null here.
		 * To display bank logos, construct the URL from the id:
		 * https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/<id>.svg
		 *
		 * @param string $paymentModule Adyen payment method type, e.g. 'ideal', 'eps'
		 * @return array
		 * @throws PaymentInitiationException
		 */
		public function getPaymentOptions(string $paymentModule): array {
			// Translate internal module name (e.g. 'adyen_ideal') to Adyen's type string (e.g. 'ideal')
			$type = self::MODULE_TYPE_MAP[$paymentModule] ?? $paymentModule;
			
			// Fetch config
			$config = $this->getConfig();
			
			// Amount is required by some methods to filter correctly (e.g. BNPL minimum thresholds)
			if (isset($config['default_currency'])) {
				$currency = ['currency' => $config['default_currency'], 'value' => 0];
			} else {
				$currency = null;
			}
			
			// Remove countryCode from payload if it's null
			$payload = array_filter([
				'merchantAccount'       => $config['merchant_account'],
				'countryCode'           => $config['default_country'] ?? null,
				'amount'                => $currency,
				'channel'               => 'Web',
				'allowedPaymentMethods' => [$type],
			], fn($v) => $v !== null);
			
			// Call API to get payment methods
			$result = $this->getGateway()->getPaymentMethods($payload);
			
			// If this failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException('adyen', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Issuers are embedded inside the matching method object, not returned as a top-level list
			$methods = $result['response']['paymentMethods'] ?? [];
			$match = current(array_filter($methods, fn($m) => ($m['type'] ?? '') === $type));
			return $this->normalizeIssuers($match['issuers'] ?? []);
		}

		/**
		 * Initiates a new Adyen payment session using the Sessions flow.
		 * Creates a server-side session via POST /checkout/v71/sessions. The returned
		 * sessionId and sessionData are handed to the front-end Drop-in component, which
		 * handles the payment UI and 3DS entirely — no server-side capture step is needed.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/sessions
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$config = $this->getConfig();
			
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
				'shopperEmail'    => $request->billingAddress?->email ?: null,
				'shopperLocale'   => 'nl-NL',
				'countryCode'     => $request->billingAddress?->country ?: null,
				'channel'         => 'Web',
				'metadata'        => [
					'brandName' => $config['brand_name'] ?: null,
				],
			], fn($v) => $v !== null && $v !== '' && $v !== []);
			
			// Create a new payment session
			$result = $this->getGateway()->createSession($payload);
			
			// If that failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException('adyen', $result['request']['errorId'], $result['request']['errorMessage']);
			}

			// Grab the response
			$response = $result['response'];
			
			// The front-end Drop-in is initialised with sessionId + sessionData and drives
			// the entire payment flow — including any redirects for iDEAL, Sofort, etc.
			// The returnUrl was passed in the session payload above; the Drop-in uses it
			// internally. There is no redirect URL to hand back to the caller here.
			return new InitiateResult(
				'adyen',
				$response['id'],
				null,
				[
					'sessionData' => $response['sessionData'],
					'clientKey'   => $config['client_key'],
					'environment' => $config['test_mode'] ? 'test' : 'live',
				]
			);
		}
		
		/**
		 * Exchanges a redirectResult (appended to the return URL after a Drop-in redirect)
		 * or a webhook pspReference for a normalised PaymentState.
		 *
		 * For return-URL calls: Adyen appends ?sessionId=...&redirectResult=... to the return URL.
		 * The controller passes action='return' and the redirectResult in extraData.
		 * We submit it to POST /checkout/v71/payments/details to resolve the final result code.
		 *
		 * For webhook calls: the AUTHORISATION or REFUND notification already contains the final
		 * state. The controller passes action='webhook' and the decoded NotificationRequestItem
		 * in extraData['notification']. No API call is needed.
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
			}
			
			// action='return': resolve the redirectResult against /payments/details
			$redirectResult = $extraData['redirectResult'] ?? null;
			
			if (empty($redirectResult)) {
				throw new PaymentExchangeException('adyen', 0, "Missing 'redirectResult' in extraData for action='return'.");
			}
			
			$payload = array_filter([
				'details'     => ['redirectResult' => $redirectResult],
				'paymentData' => $extraData['paymentData'] ?? null,
			], fn($v) => $v !== null);
			
			$result = $this->getGateway()->getPaymentDetails($payload);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException('adyen', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			return $this->buildStateFromResultCode($transactionId, $result['response']);
		}
		
		/**
		 * Refunds a previously captured payment.
		 * For a full refund pass $request->amount === $originalAmount.
		 * Adyen handles partial refunds with the same endpoint — the difference is solely the amount.
		 * @see https://docs.adyen.com/api-explorer/Checkout/71/post/payments/{paymentPspReference}/refunds
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// pspReference of the original payment must be stored in PaymentState::$metadata['pspReference']
			$pspReference = $request->metadata['pspReference'] ?? null;
			
			if (empty($pspReference)) {
				throw new PaymentRefundException(
					'adyen',
					0,
					"Cannot refund: pspReference is missing from metadata. " .
					"Ensure your payment_exchange listener persists PaymentState::\$metadata['pspReference']."
				);
			}
			
			$result = $this->getGateway()->refundPayment(
				$pspReference,
				$request->amount,
				$request->currency,
				$request->note ?? ''
			);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException('adyen', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Adyen returns status='received' immediately; the actual outcome arrives via REFUND webhook.
			// We return the refund pspReference so callers can correlate the incoming webhook.
			return new RefundResult(
				provider: 'adyen',
				transactionId: $request->transactionId,
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
		 * @param string $transactionId
		 * @return RefundResult[]
		 */
		public function getRefunds(string $transactionId): array {
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
		 * Maps an Adyen /payments/details resultCode response to a PaymentState.
		 * resultCode is the canonical status field for synchronous responses.
		 * @see https://docs.adyen.com/online-payments/payment-result-codes/
		 * @param string $transactionId The sessionId used to initiate the payment
		 * @param array $response The decoded /payments/details response body
		 * @return PaymentState
		 */
		private function buildStateFromResultCode(string $transactionId, array $response): PaymentState {
			$resultCode = $response['resultCode'] ?? 'Unknown';
			$pspReference = $response['pspReference'] ?? null;
			$currency = $response['amount']['currency'] ?? '';
			$valuePaid = $response['amount']['value'] ?? 0;
			
			$state = match ($resultCode) {
				// Payment was authorised and (for ecommerce with auto-capture) funds will be collected.
				'Authorised' => PaymentStatus::Paid,
				// Shopper cancelled before completing — treat as a clean cancellation.
				'Cancelled' => PaymentStatus::Canceled,
				// Payment was refused by the issuer or Adyen risk engine.
				'Refused', 'Error' => PaymentStatus::Failed,
				// Asynchronous payment methods (e.g. bank transfers, vouchers) — await webhook.
				'Pending', 'Received' => PaymentStatus::Pending,
				// presentToShopper, identifyShopper, challengeShopper: should not reach the return URL
				// in the Sessions flow, but treat as pending to avoid data loss.
				default => PaymentStatus::Pending,
			};
			
			return new PaymentState(
				provider: 'adyen',
				transactionId: $transactionId,
				state: $state,
				valuePaid: $state === PaymentStatus::Paid ? (int)$valuePaid : 0,
				valueRefunded: 0,
				internalState: $resultCode,
				currency: $currency,
				metadata: array_filter([
					'pspReference'  => $pspReference,
					'paymentMethod' => $response['paymentMethod']['type'] ?? null,
					'refusalReason' => $response['refusalReason'] ?? null,
				], fn($v) => $v !== null),
			);
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
			
			$valueRefunded = 0;
			
			if ($eventCode === 'REFUND' && $success) {
				// For REFUND webhooks the amount field reflects the refunded amount.
				$valueRefunded = $value;
				$value = 0;
			}
			
			return new PaymentState(
				provider: 'adyen',
				transactionId: $transactionId,
				state: $state,
				valuePaid: in_array($state, [PaymentStatus::Paid]) ? $value : 0,
				valueRefunded: $valueRefunded,
				internalState: $eventCode,
				currency: $currency,
				metadata: array_filter([
					'pspReference'      => $pspReference,
					'merchantReference' => $notification['merchantReference'] ?? null,
					'paymentMethod'     => $notification['paymentMethod'] ?? null,
					'reason'            => $notification['reason'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		
		/**
		 * Normalizes an Adyen issuer list into the flat shape expected by the frontend.
		 * Adyen issuer objects contain: id (string), name (string), disabled (bool, optional).
		 * For iDEAL, the id is the BIC (e.g. BUNKNL2A), which is also the SWIFT code.
		 * Adyen does not include icons in the /paymentMethods issuer list — use the logo
		 * download endpoint to enrich icons if needed.
		 * @see https://docs.adyen.com/payment-methods/downloading-logos/
		 * @param array $issuers Raw issuer list from the Adyen /paymentMethods response
		 * @return array
		 */
		private function normalizeIssuers(array $issuers): array {
			return array_values(array_map(fn($issuer) => [
				'id'       => $issuer['id'],
				'name'     => $issuer['name'],
				'issuerId' => $issuer['id'],
				'swift'    => $issuer['id'],
				// Adyen does not return icons in the issuer list — null here is intentional.
				// To display bank logos, fetch them from:
				// https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/<id>.svg
				'icon'     => null,
			], $issuers));
		}
	}