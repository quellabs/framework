<?php
	
	namespace Quellabs\Payments\XPay;
	
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
		 * Driver name
		 */
		const DRIVER_NAME = "xpay";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var XPayGateway|null
		 */
		private ?XPayGateway $gateway = null;
		
		/**
		 * Maps our internal module names to XPay paymentService values.
		 *
		 * These are passed as paymentSession.paymentService in the order request.
		 * When paymentService is omitted, XPay shows all payment methods enabled on the terminal.
		 * Providing a specific value restricts the hosted page to a single payment method.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/docs/hosted-payment-page/
		 */
		private const MODULE_TYPE_MAP = [
			'xpay'             => null,           // All enabled methods on the terminal
			'xpay_cards'       => 'CARDS',
			'xpay_applepay'    => 'APPLEPAY',
			'xpay_googlepay'   => 'GOOGLEPAY',
			'xpay_paypal'      => 'PAYPAL',
			'xpay_mybank'      => 'MYBANK',
			'xpay_bancomatpay' => 'BANCOMATPAY',
			'xpay_klarna'      => 'KLARNA',
			'xpay_alipay'      => 'ALIPAY',
			'xpay_wechatpay'   => 'WECHATPAY',
			
			
		];
		
		/**
		 * Maps XPay operationResult values to our internal PaymentStatus.
		 *
		 * XPay operationResult values:
		 *   AUTHORIZED        — payment successfully authorised and captured
		 *   PENDING           — awaiting outcome (async methods, bank redirects)
		 *   VOIDED            — authorisation reversed before capture
		 *   CANCELLED         — order cancelled before payment
		 *   DENIED_BY_RISK    — rejected by risk engine
		 *   THREEDS_VALIDATED — 3DS challenge passed, awaiting capture
		 *   THREEDS_FAILED    — 3DS challenge failed
		 *   FAILED            — payment failed at the acquirer
		 *   REVERSED          — refund successfully processed
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/
		 */
		private const RESULT_STATUS_MAP = [
			'AUTHORIZED'        => PaymentStatus::Paid,
			'PENDING'           => PaymentStatus::Pending,
			'VOIDED'            => PaymentStatus::Canceled,
			'CANCELLED'         => PaymentStatus::Canceled,
			'DENIED_BY_RISK'    => PaymentStatus::Failed,
			'THREEDS_VALIDATED' => PaymentStatus::Pending,
			'THREEDS_FAILED'    => PaymentStatus::Failed,
			'FAILED'            => PaymentStatus::Failed,
			'REVERSED'          => PaymentStatus::Refunded,
		];
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
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
		 * @return array
		 */
		public function getDefaults(): array {
			return [
				'api_key'           => '',
				'return_url'        => '',
				'return_url_cancel' => '',
				'webhook_url'       => '',
				'default_language'  => 'ENG',
			];
		}
		
		/**
		 * Returns available payment options for a given module.
		 * XPay does not expose a pre-selection issuer or bank list via API — the hosted
		 * page handles method selection. Always returns empty.
		 * @param string $paymentModule
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Initiates a payment by creating an XPay order for the Hosted Payment Page.
		 *
		 * XPay returns a hostedPage URL to redirect the shopper to. After the payment
		 * completes (or is cancelled), XPay redirects to the configured resultUrl or cancelUrl.
		 * The orderId is used to fetch the authoritative status via GET /orders/{orderId}.
		 *
		 * Amount note: XPay uses minor units (smallest currency unit), which matches the
		 * PaymentRequest convention — no conversion needed.
		 *
		 * The orderId is your PaymentRequest::$reference (up to 27 alphanumeric chars).
		 * XPay ties the order to this reference; it is returned in the return URL and
		 * push notification. We store it as the transactionId in InitiateResult so that
		 * exchange() and refund() can pass it back to the API.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/#orders-hpp-post
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$config = $this->getConfig();
			
			// Resolve the XPay paymentService for this module (null = all enabled methods)
			$paymentService = self::MODULE_TYPE_MAP[$request->paymentModule] ?? null;
			
			// Build the paymentSession block
			$paymentSession = [
				'actionType' => 'PAY',
				'amount'     => (string)$request->amount,
				'resultUrl'  => $config['return_url'],
				'cancelUrl'  => $config['return_url_cancel'],
				'language'   => $config['default_language'],
			];
			
			// notificationUrl triggers server-to-server push notifications after each state change
			if (!empty($config['webhook_url'])) {
				$paymentSession['notificationUrl'] = $config['webhook_url'];
			}
			
			// Restrict hosted page to a specific payment method if a non-null service is mapped
			if ($paymentService !== null) {
				$paymentSession['paymentService'] = $paymentService;
			}
			
			// Build the order block
			$order = [
				'orderId'     => $request->reference,
				'amount'      => (string)$request->amount,
				'currency'    => $request->currency,
				'description' => $request->description,
			];
			
			// Build customerInfo from billing or shipping address if present
			$customerInfo = $this->buildCustomerInfo($request);
			
			if (!empty($customerInfo)) {
				$order['customerInfo'] = $customerInfo;
			}
			
			$payload = [
				'paymentSession' => $paymentSession,
				'order'          => $order,
			];
			
			$result = $this->getGateway()->createOrder($payload);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$response    = $result['response'];
			$hostedPage  = $response['hostedPage'] ?? '';
			
			if (empty($hostedPage)) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, 'Missing hostedPage URL in XPay response');
			}
			
			// The orderId (our reference) is the identifier we carry forward.
			// XPay does not return a separate transaction key — the orderId IS the reference.
			return new InitiateResult(
				provider:      self::DRIVER_NAME,
				transactionId: $request->reference,
				redirectUrl:   $hostedPage,
			);
		}
		
		/**
		 * Resolves the authoritative payment state for a given order.
		 *
		 * XPay uses a pull model: neither the return URL nor the push notification
		 * contains the payment result. The canonical status comes from GET /orders/{orderId}.
		 *
		 * The response contains an `operations` array. We scan for the most recent
		 * CAPTURE operation to determine whether the payment succeeded, and any REFUND
		 * operations to compute the total refunded amount.
		 *
		 * operationResult values and their mapping:
		 *   AUTHORIZED        → Paid
		 *   PENDING           → Pending
		 *   VOIDED / CANCELLED → Canceled
		 *   DENIED_BY_RISK / THREEDS_FAILED / FAILED → Failed
		 *   REVERSED          → Refunded (refund operation)
		 *
		 * @param string $transactionId The orderId (PaymentRequest::$reference)
		 * @param array $extraData action: 'return' | 'push' (informational only)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			$result = $this->getGateway()->getOrder($transactionId);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$data       = $result['response'];
			$orderData  = $data['orderStatus']['order'] ?? [];
			$operations = $data['orderStatus']['operations'] ?? [];
			$currency   = $orderData['currency'] ?? '';
			
			// Walk operations to find the primary payment status and refund total.
			// Operations are ordered chronologically; we want the most recent CAPTURE result.
			$captureOp   = null;
			$refundTotal = 0;
			
			foreach ($operations as $op) {
				$opType   = strtoupper($op['operationType'] ?? '');
				$opResult = strtoupper($op['operationResult'] ?? '');
				
				if ($opType === 'CAPTURE' || $opType === 'AUTHORIZATION') {
					// Keep the last capture/auth operation as the primary
					$captureOp = $op;
				}
				
				if ($opType === 'REFUND' && $opResult === 'AUTHORIZED') {
					$refundTotal += (int)($op['operationAmount'] ?? 0);
				}
			}
			
			// If no capture operation found at all, fall back to the first operation or Pending
			if ($captureOp === null && !empty($operations)) {
				$captureOp = reset($operations);
			}
			
			$opResult    = strtoupper($captureOp['operationResult'] ?? 'PENDING');
			$opAmount    = (int)($captureOp['operationAmount'] ?? 0);
			$opCurrency  = $captureOp['operationCurrency'] ?? $currency;
			$operationId = $captureOp['operationId'] ?? null;
			
			$state = self::RESULT_STATUS_MAP[$opResult] ?? PaymentStatus::Pending;
			
			// If there are refunds that fully cover the paid amount, upgrade state to Refunded
			if ($state === PaymentStatus::Paid && $refundTotal > 0 && $refundTotal >= $opAmount) {
				$state = PaymentStatus::Refunded;
			}
			
			$valuePaid     = $state === PaymentStatus::Paid ? $opAmount : 0;
			$valueRefunded = $refundTotal;
			
			return new PaymentState(
				provider:      self::DRIVER_NAME,
				transactionId: $transactionId,
				state:         $state,
				currency:      $opCurrency ?: $currency,
				valuePaid:     $valuePaid,
				valueRefunded: $valueRefunded,
				internalState: $opResult,
				metadata:      array_filter([
					'operationId'   => $operationId,
					'paymentMethod' => $captureOp['paymentMethod'] ?? null,
					'paymentCircuit'=> $captureOp['paymentCircuit'] ?? null,
					'operationType' => $captureOp['operationType'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Refunds a previously captured payment.
		 *
		 * XPay refunds require the operationId of the original CAPTURE operation, not the orderId.
		 * We first fetch the order to locate the CAPTURE operationId, then POST to
		 * /operations/{operationId}/refunds.
		 *
		 * The paymentReference on RefundRequest must be the orderId (our transactionId from initiate()).
		 *
		 * Omitting the amount triggers a full refund. Providing an amount triggers a partial refund.
		 *
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/#operations-operationid-refunds-post
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Resolve the CAPTURE operationId for this order — the refund endpoint requires it
			$captureOperationId = $this->resolveCaptureOperationId($request->paymentReference);
			
			// Build the refund payload
			$payload = [];
			
			// Partial refund: include amount and currency
			if ($request->amount !== null) {
				$payload['amount']   = (string)$request->amount;
				$payload['currency'] = $request->currency;
			}
			
			// Optional description
			if (!empty($request->description)) {
				$payload['description'] = $request->description;
			}
			
			$result = $this->getGateway()->refundOperation($captureOperationId, $payload);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// The refund response contains the new operation object for the refund itself
			$response    = $result['response'];
			$refundOpId  = $response['operationId'] ?? '';
			$refundedAmt = (int)($response['operationAmount'] ?? ($request->amount ?? 0));
			$currency    = $response['operationCurrency'] ?? $request->currency;
			
			return new RefundResult(
				provider:         self::DRIVER_NAME,
				paymentReference: $request->paymentReference,
				refundId:         $refundOpId,
				value:            $refundedAmt,
				currency:         $currency,
			);
		}
		
		/**
		 * Returns a list of RefundResult objects for all completed refunds on an order.
		 *
		 * XPay embeds refund operations directly in the order's operations list.
		 * We filter for operationType=REFUND with operationResult=AUTHORIZED.
		 *
		 * @param string $paymentReference The orderId
		 * @return RefundResult[]
		 * @throws PaymentExchangeException
		 */
		public function getRefunds(string $paymentReference): array {
			$result = $this->getGateway()->getOrder($paymentReference);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			$operations = $result['response']['orderStatus']['operations'] ?? [];
			$currency   = $result['response']['orderStatus']['order']['currency'] ?? '';
			$refunds    = [];
			
			foreach ($operations as $op) {
				$opType   = strtoupper($op['operationType'] ?? '');
				$opResult = strtoupper($op['operationResult'] ?? '');
				
				if ($opType !== 'REFUND' || $opResult !== 'AUTHORIZED') {
					continue;
				}
				
				$refunds[] = new RefundResult(
					provider:         self::DRIVER_NAME,
					paymentReference: $paymentReference,
					refundId:         $op['operationId'] ?? '',
					value:            (int)($op['operationAmount'] ?? 0),
					currency:         $op['operationCurrency'] ?? $currency,
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Finds and returns the operationId of the most recent successful CAPTURE operation
		 * for the given orderId. Required by refund() because XPay's refund endpoint takes
		 * an operationId, not an orderId.
		 *
		 * @param string $orderId
		 * @return string CAPTURE operationId
		 * @throws PaymentRefundException When the order cannot be fetched or no capture found
		 */
		private function resolveCaptureOperationId(string $orderId): string {
			$result = $this->getGateway()->getOrder($orderId);
			
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result['request']['errorId'], 'Could not fetch order to resolve capture operationId: ' . $result['request']['errorMessage']);
			}
			
			$operations = $result['response']['orderStatus']['operations'] ?? [];
			$captureOpId = null;
			
			foreach ($operations as $op) {
				$opType   = strtoupper($op['operationType'] ?? '');
				$opResult = strtoupper($op['operationResult'] ?? '');
				
				if (($opType === 'CAPTURE' || $opType === 'AUTHORIZATION') && $opResult === 'AUTHORIZED') {
					$captureOpId = $op['operationId'] ?? null;
				}
			}
			
			if (empty($captureOpId)) {
				throw new PaymentRefundException(self::DRIVER_NAME, 0, 'No authorised CAPTURE operation found for order: ' . $orderId);
			}
			
			return $captureOpId;
		}
		
		/**
		 * Builds an XPay customerInfo object from the PaymentRequest addresses.
		 * Used to pre-fill the hosted page and improve fraud scoring / SCA exemption chances.
		 *
		 * XPay address fields: name, street, additionalInfo, city, postCode, province, country (ISO 3166-1 alpha-3).
		 * Note: XPay uses 3-letter country codes (ITA, NLD) unlike Buckaroo which uses 2-letter (IT, NL).
		 *
		 * @param PaymentRequest $request
		 * @return array customerInfo array, possibly empty if no address data is available
		 */
		private function buildCustomerInfo(PaymentRequest $request): array {
			$info = [];
			
			$billing  = $request->billingAddress;
			$shipping = $request->shippingAddress;
			$primary  = $billing ?? $shipping;
			
			if ($primary === null) {
				return [];
			}
			
			// Top-level cardholder fields
			if (!empty($primary->givenName) || !empty($primary->familyName)) {
				$info['cardHolderName'] = trim(($primary->givenName ?? '') . ' ' . ($primary->familyName ?? ''));
			}
			
			if (!empty($primary->email)) {
				$info['cardHolderEmail'] = $primary->email;
			}
			
			if (!empty($primary->phone)) {
				// Split country code from local number if the phone is in +31612345678 format
				if (str_starts_with($primary->phone, '+')) {
					// Naive extraction: first 1-3 digits after '+' are the country code
					if (preg_match('/^\+(\d{1,3})(\d+)$/', $primary->phone, $m)) {
						$info['mobilePhoneCountryCode'] = $m[1];
						$info['mobilePhone']            = $m[2];
					}
				} else {
					$info['mobilePhone'] = $primary->phone;
				}
			}
			
			if ($billing !== null) {
				$addr = $this->buildAddressBlock($billing);
				
				if (!empty($addr)) {
					$info['billingAddress'] = $addr;
				}
			}
			
			if ($shipping !== null) {
				$addr = $this->buildAddressBlock($shipping);
				
				if (!empty($addr)) {
					$info['shippingAddress'] = $addr;
				}
			} elseif ($billing !== null) {
				// XPay fraud scoring benefits from having shippingAddress even when equal to billing
				$addr = $this->buildAddressBlock($billing);
				
				if (!empty($addr)) {
					$info['shippingAddress'] = $addr;
				}
			}
			
			return $info;
		}
		
		/**
		 * Converts a PaymentAddress into an XPay address block.
		 * @param PaymentAddress $address
		 * @return array XPay-formatted address fields (only non-empty values included)
		 */
		private function buildAddressBlock(PaymentAddress $address): array {
			$streetLine = trim(($address->street ?? '') . ' ' . ($address->houseNumber ?? '') . ($address->houseNumberSuffix ? '-' . $address->houseNumberSuffix : ''));
			
			$fields = [
				'name'     => trim(($address->givenName ?? '') . ' ' . ($address->familyName ?? '')),
				'street'   => $streetLine ?: null,
				'city'     => $address->city,
				'postCode' => $address->postalCode,
				'province' => $address->region,
				'country'  => $address->country, // caller should pass ISO 3166-1 alpha-3 (ITA, NLD, DEU…)
			];
			
			return array_filter($fields, fn($v) => $v !== null && $v !== '');
		}
		
		/**
		 * Lazily instantiates the XPayGateway.
		 * @return XPayGateway
		 */
		private function getGateway(): XPayGateway {
			return $this->gateway ??= new XPayGateway($this);
		}
	}