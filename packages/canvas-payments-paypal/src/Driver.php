<?php
	
	namespace Quellabs\Payments\Paypal;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentInterface;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentInitiationException;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Payments\Contracts\RefundResult;
	
	/**
	 * PayPal Driver
	 * @phpstan-import-type IssuerOption from PaymentInterface
	 */
	class Driver implements PaymentProviderInterface {
		
		use GatewayHelpers;
		
		/**
		 * Driver name
		 */
		const string DRIVER_NAME = "paypal";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
		 */
		private array $config;
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var PaypalGateway|null
		 */
		private ?PaypalGateway $gateway = null;
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => ['paypal']
			];
		}
		
		/**
		 * Returns the active configuration for this provider instance.
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this provider instance.
		 * Called by the discovery system after instantiation, before any other methods are invoked.
		 * @param array<string, mixed> $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this provider.
		 * Merged with loaded config files during discovery — values from config files take precedence.
		 * @return array{
		 *     test_mode: bool,
		 *     client_id: string,
		 *     client_secret: string,
		 *     brand_name: string,
		 *     account_optional: bool,
		 *     verify_ssl: bool,
		 *     webhook_id: string,
		 *     return_url: string,
		 *     cancel_return_url: string
		 * }
		 */
		public function getDefaults(): array {
			return [
				'test_mode'         => false,
				'client_id'         => '',
				'client_secret'     => '',
				'brand_name'        => '',
				'account_optional'  => true,
				'verify_ssl'        => true,
				'webhook_id'        => '',
				'return_url'        => '',
				'cancel_return_url' => '',
			];
		}
		
		/**
		 * Initiate a new payment session by creating a PayPal order.
		 * Returns a redirect URL pointing to the PayPal checkout page.
		 * The order ID serves as the transactionId throughout the payment lifecycle.
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_create
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			// Fetch config
			$config = $this->getConfig();
			
			// Extract brand_name from config — getConfig() returns array<string, mixed> so we
			// validate the type explicitly rather than casting.
			$brandName = isset($config['brand_name']) && is_string($config['brand_name']) ? $config['brand_name'] : '';
			
			// Use the gateway to create a new order
			$result = $this->getGateway()->createOrder(
				$request->amount / 100,
				$request->description,
				$request->currency,
				$brandName,
			);
			
			// If that failed, throw error
			if ($result["request"]["result"] === 0) {
				throw new PaymentInitiationException(self::DRIVER_NAME, $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Fetch response
			$response = $result["response"] ?? [];
			
			// Validate response
			if (
				!isset($response["id"]) ||
				!isset($response["links"]) ||
				!is_array($response["links"])
			) {
				throw new PaymentInitiationException(self::DRIVER_NAME, "500", "Invalid gateway response. Missing id and/or redirect url");
			}
			
			// Fetch orderId — validated above that ["id"] exists, but it must be a string
			if (!is_string($response["id"])) {
				throw new PaymentInitiationException(self::DRIVER_NAME, "500", "Invalid gateway response. Order id is not a string");
			}
			
			$transactionId = $response["id"];
			
			// Extract the HATEOAS approve link — this is the URL we redirect the buyer to.
			// Using the link from the response is more robust than constructing it manually,
			// as PayPal controls its format.
			$approveUrl = null;
			
			foreach ($response["links"] as $link) {
				// Skip entries that are not arrays
				if (!is_array($link)) {
					continue;
				}
				
				// Extract approve url
				if (isset($link["rel"]) && $link["rel"] === "payer-action") {
					$approveUrl = isset($link["href"]) && is_string($link["href"]) ? $link["href"] : null;
					break;
				}
			}
			
			// Validate approveUrl
			if ($approveUrl === null) {
				throw new PaymentInitiationException(self::DRIVER_NAME, "MISSING_APPROVE_LINK", "PayPal response did not include a payer-action link.");
			}
			
			// Return result
			return new InitiateResult(
				self::DRIVER_NAME,
				$transactionId,
				$approveUrl,
			);
		}
		
		/**
		 * Called when PayPal redirects the buyer back to the return URL, or when a webhook arrives.
		 * Fetches the current order status and either captures payment or maps the existing state.
		 *
		 * extraData keys:
		 *   'action'    — 'cancel' | 'return' | 'webhook'
		 *   'paymentReference' — the capture ID from a PAYMENT.CAPTURE.* webhook payload, enables
		 *                 refund-state retrieval without re-fetching the order
		 *
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_get
		 * @param string $transactionId The order ID returned by initiate()
		 * @param array<string, mixed> $extraData
		 * @return PaymentState
		 * @throws PaymentExchangeException|PaymentInitiationException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Fetch config
			$config          = $this->getConfig();
			$defaultCurrency = $this->normalizeString($config['default_currency'] ?? null, 'EUR');
			
			// Buyer clicked cancel at PayPal — no payment was attempted.
			// Return a canceled state without querying the API.
			if (($extraData['action'] ?? null) === 'cancel') {
				return new PaymentState(
					provider: self::DRIVER_NAME,
					transactionId: $transactionId,
					state: PaymentStatus::Canceled,
					currency: $defaultCurrency,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: "cancel",
				);
			}
			
			// Fetch the current order state from PayPal
			$order = $this->getGateway()->getOrder($transactionId);
			
			// Validate this went correctly
			if ($order["request"]["result"] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $order["request"]["errorId"], $order["request"]["errorMessage"]);
			}
			
			// Map order status to PaymentState
			$orderData     = $order["response"] ?? [];
			$orderStatus   = $this->normalizeString($orderData["status"] ?? null);
			$purchaseUnits = isset($orderData["purchase_units"]) && is_array($orderData["purchase_units"]) ? $orderData["purchase_units"] : [];
			$purchaseUnit  = isset($purchaseUnits[0]) && is_array($purchaseUnits[0]) ? $purchaseUnits[0] : [];
			$amountBlock   = isset($purchaseUnit["amount"]) && is_array($purchaseUnit["amount"]) ? $purchaseUnit["amount"] : [];
			$currency      = $this->normalizeString($amountBlock["currency_code"] ?? null, $defaultCurrency);
			
			switch ($orderStatus) {
				// Order was created but the buyer hasn't approved it yet (should not normally
				// reach exchange() in this state)
				case "CREATED":
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $transactionId,
						state: PaymentStatus::Pending,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: "CREATED",
					);
				
				// Buyer has approved payment at PayPal — capture it now
				case "APPROVED":
					return $this->captureOrder($transactionId, $currency);
				
				// Payment was already captured in a previous exchange() call.
				// Build state from the existing capture to retrieve current refund amounts.
				case "COMPLETED":
					$captureId = $this->extractCaptureId($extraData, $purchaseUnit);
					return $this->buildCompletedPaymentState($transactionId, $captureId, "COMPLETED", $currency);
				
				// Order was voided. This can mean a genuine cancellation (valueRefunded = 0)
				// or that all captures were fully refunded. Fetch the capture to determine
				// the actual refunded amount rather than assuming zero.
				case "VOIDED":
					$captureId = $this->extractCaptureId($extraData, $purchaseUnit);
					
					// No capture means the order was cancelled before payment — clean cancellation
					if ($captureId === null) {
						return new PaymentState(
							provider: self::DRIVER_NAME,
							transactionId: $transactionId,
							state: PaymentStatus::Canceled,
							currency: $currency,
							valuePaid: 0,
							valueRefunded: 0,
							internalState: "VOIDED",
						);
					}
					
					// Capture exists — fetch it to get the refunded amount
					return $this->buildCompletedPaymentState($transactionId, $captureId, "VOIDED", $currency);
				
				// PAYER_ACTION_REQUIRED — 3DS or other additional authentication needed.
				// Equivalent to NVP error 10486: redirect buyer back to PayPal.
				case "PAYER_ACTION_REQUIRED":
					$redirectUrl = null;
					
					$orderLinks = isset($orderData["links"]) && is_array($orderData["links"]) ? $orderData["links"] : [];
					
					foreach ($orderLinks as $link) {
						if (!is_array($link)) {
							continue;
						}
						
						if (isset($link["rel"]) && $link["rel"] === "payer-action") {
							$redirectUrl = isset($link["href"]) && is_string($link["href"]) ? $link["href"] : null;
							break;
						}
					}
					
					// Fall back to constructing the URL if PayPal didn't include the link
					if ($redirectUrl === null) {
						if ($this->getGateway()->testMode()) {
							$redirectUrl = "https://www.sandbox.paypal.com/checkoutnow?token={$transactionId}";
						} else {
							$redirectUrl = "https://www.paypal.com/checkoutnow?token={$transactionId}";
						}
					}
					
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $transactionId,
						state: PaymentStatus::Redirect,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: "PAYER_ACTION_REQUIRED",
						metadata: [
							"redirectUrl" => $redirectUrl,
						],
					);
				
				default:
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $transactionId,
						state: PaymentStatus::Pending,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: $orderStatus,
					);
			}
		}
		
		/**
		 * Refund a PayPal payment, either fully or partially.
		 *
		 * Note: $request->paymentReference must be the capture ID, not the order ID.
		 * This is available in PaymentState::$metadata['paymentReference'] after a successful exchange().
		 *
		 * @see https://developer.paypal.com/docs/api/payments/v2/#captures_refund
		 * @param RefundRequest $request amount=null for full refund, or a minor-unit integer for partial
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			$value    = $request->amount !== null ? $request->amount / 100 : null;
			$currency = $request->amount !== null ? $request->currency : null;
			
			// Deterministic key derived from capture ID + amount — retrying the same refund
			// request always produces the same key, so a timeout cannot cause a double-refund.
			// Amount is included so a partial refund followed by a different partial refund
			// on the same capture produces a distinct key.
			$idempotencyKey = hash('sha256', 'refund:' . $request->paymentReference . ':' . ($request->amount ?? 'full'));
			
			// Call API to create the refund
			$result = $this->getGateway()->refund(
				$request->paymentReference,
				$value,
				$currency,
				$request->description,
				$idempotencyKey,
			);
			
			// If that failed, throw an exception
			if ($result["request"]["result"] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Send response back to user
			$response = $result["response"] ?? [];
			
			// Response should contain an id field
			if (!isset($response["id"]) || !is_string($response["id"])) {
				throw new PaymentRefundException(self::DRIVER_NAME, "INVALID_RESPONSE", "Refund response is missing a valid id");
			}
			
			$refundAmountBlock = isset($response["amount"]) && is_array($response["amount"]) ? $response["amount"] : [];
			$refundCurrency    = $this->normalizeString($refundAmountBlock["currency_code"] ?? null, $request->currency);
			$refundValue       = (int)round($this->toFloat($refundAmountBlock["value"] ?? null) * 100);
			$breakdown         = isset($response["seller_payable_breakdown"]) && is_array($response["seller_payable_breakdown"]) ? $response["seller_payable_breakdown"] : [];
			$totalRefunded     = isset($breakdown["total_refunded_amount"]) && is_array($breakdown["total_refunded_amount"]) ? $breakdown["total_refunded_amount"] : [];
			
			return new RefundResult(
				provider: self::DRIVER_NAME,
				paymentReference: $request->paymentReference,
				refundId: $response["id"],
				value: $refundValue,
				currency: $refundCurrency,
				metadata: [
					"status"     => isset($response["status"]) && is_string($response["status"]) ? $response["status"] : null,
					"sellerNote" => $this->normalizeString($totalRefunded["value"] ?? null),
				],
			);
		}
		
		/**
		 * Other payment modules would use this to receive a list of banks or similar.
		 * PayPal does not use it.
		 * @param string $paymentModule
		 * @return array<int, IssuerOption>
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Returns all refunds issued for a given capture.
		 *
		 * Note: $transactionId must be the capture ID, not the order ID.
		 * This is available in PaymentState::$metadata['paymentReference'] after a successful exchange().
		 *
		 * @see https://developer.paypal.com/docs/api/payments/v2/#captures_get
		 * @param string $paymentReference The capture ID
		 * @return array<RefundResult>
		 * @throws PaymentRefundException
		 */
		public function getRefunds(string $paymentReference): array {
			// Call the API to fetch all refunds
			$result = $this->getGateway()->getRefundsForCapture($paymentReference);
			
			// If that failed, throw an error
			if ($result["request"]["result"] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Fetch the response
			$response = $result["response"] ?? [];
			
			// Return empty array if there are no refunds
			if (empty($response["refunds"])) {
				return [];
			}
			
			
			// Create iterable refund list
			$refundList = is_array($response["refunds"]) ? $response["refunds"] : [];
			
			// Flatten the refund list
			$refunds = [];
			
			foreach ($refundList as $refund) {
				if (!is_array($refund)) {
					continue;
				}
				
				if (!isset($refund["id"]) || !is_string($refund["id"])) {
					continue;
				}
				
				if (!isset($refund["amount"]) || !is_array($refund["amount"])) {
					continue;
				}
				
				if (!isset($refund["amount"]["value"])) {
					continue;
				}
				
				$refunds[] = new RefundResult(
					provider: self::DRIVER_NAME,
					paymentReference: $paymentReference,
					refundId: $refund["id"],
					value: (int)round($this->toFloat($refund["amount"]["value"]) * 100),
					currency: $this->normalizeString($refund["amount"]["currency_code"] ?? null),
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Verifies a PayPal webhook notification by delegating signature validation to the gateway.
		 * @param array<string, mixed> $headers The request headers (lowercased keys)
		 * @param string $rawBody The raw, unmodified request body string
		 * @return bool
		 */
		public function verifyWebhookSignature(array $headers, string $rawBody): bool {
			return $this->getGateway()->verifyWebhookSignature($headers, $rawBody);
		}
		
		/**
		 * Extracts the capture ID from extraData or from the purchase unit's payments.captures array.
		 * Returns null when no capture exists (e.g. order voided before capture).
		 * @param array<string, mixed> $extraData
		 * @param array<string, mixed> $purchaseUnit
		 * @return string|null
		 */
		private function extractCaptureId(array $extraData, array $purchaseUnit): ?string {
			// Prefer the caller-supplied capture ID (from webhook or previous PaymentState metadata)
			if (isset($extraData['paymentReference']) && is_string($extraData['paymentReference'])) {
				return $extraData['paymentReference'];
			}
			
			// Fall back to the first capture embedded in the order response
			$payments     = isset($purchaseUnit["payments"]) && is_array($purchaseUnit["payments"]) ? $purchaseUnit["payments"] : [];
			$captures     = isset($payments["captures"]) && is_array($payments["captures"]) ? $payments["captures"] : [];
			$firstCapture = isset($captures[0]) && is_array($captures[0]) ? $captures[0] : [];
			
			return isset($firstCapture["id"]) && is_string($firstCapture["id"]) ? $firstCapture["id"] : null;
		}
		
		/**
		 * Capture payment for an APPROVED order and map the result to a PaymentState.
		 * Called from exchange() when order status is APPROVED.
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		 * @param string $orderId The order ID
		 * @param string $currency ISO 4217 currency code from the order (used as fallback)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		private function captureOrder(string $orderId, string $currency): PaymentState {
			// Deterministic key derived from the order ID — retrying the same order always
			// produces the same key, so a network timeout cannot cause a double-capture.
			$idempotencyKey = hash('sha256', 'capture:' . $orderId);
			$result         = $this->getGateway()->captureOrder($orderId, $idempotencyKey);
			
			if ($result["request"]["result"] === 0) {
				$errorId = $result["request"]["errorId"];
				
				// INSTRUMENT_DECLINED is the REST equivalent of NVP error 10486:
				// the buyer's funding source was declined. Redirect them back to PayPal
				// to choose a different payment method.
				// PayPal includes a payer-action HATEOAS link in the error response body pointing
				// to the correct retry URL — prefer that over constructing the URL manually.
				// @see https://developer.paypal.com/docs/checkout/standard/customize/handle-funding-failures/
				if ($errorId === "INSTRUMENT_DECLINED") {
					$redirectUrl = null;
					
					$responseData  = isset($result["response"]) && is_array($result["response"]) ? $result["response"] : [];
					$responseLinks = isset($responseData["links"]) && is_array($responseData["links"]) ? $responseData["links"] : [];
					
					foreach ($responseLinks as $link) {
						if (!is_array($link)) {
							continue;
						}
						
						if (isset($link["rel"]) && $link["rel"] === "payer-action") {
							$redirectUrl = isset($link["href"]) && is_string($link["href"]) ? $link["href"] : null;
							break;
						}
					}
					
					// Fall back to constructing the URL if PayPal didn't include the link
					if ($redirectUrl === null) {
						if ($this->getGateway()->testMode()) {
							$redirectUrl = "https://www.sandbox.paypal.com/checkoutnow?token={$orderId}";
						} else {
							$redirectUrl = "https://www.paypal.com/checkoutnow?token={$orderId}";
						}
					}
					
					return new PaymentState(
						provider: self::DRIVER_NAME,
						transactionId: $orderId,
						state: PaymentStatus::Redirect,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: "INSTRUMENT_DECLINED",
						metadata: [
							"redirectUrl" => $redirectUrl,
						],
					);
				}
				
				throw new PaymentExchangeException(self::DRIVER_NAME, $errorId, $result["request"]["errorMessage"]);
			}
			
			$captureResponse    = isset($result["response"]) && is_array($result["response"]) ? $result["response"] : [];
			$purchaseUnits      = isset($captureResponse["purchase_units"]) && is_array($captureResponse["purchase_units"]) ? $captureResponse["purchase_units"] : [];
			$firstUnit          = isset($purchaseUnits[0]) && is_array($purchaseUnits[0]) ? $purchaseUnits[0] : [];
			$payments           = isset($firstUnit["payments"]) && is_array($firstUnit["payments"]) ? $firstUnit["payments"] : [];
			$captures           = isset($payments["captures"]) && is_array($payments["captures"]) ? $payments["captures"] : [];
			$capture            = isset($captures[0]) && is_array($captures[0]) ? $captures[0] : [];
			$captureId          = isset($capture["id"]) && is_string($capture["id"]) ? $capture["id"] : null;
			$captureStatus      = $this->normalizeString($capture["status"] ?? null, "UNKNOWN");
			$captureAmountBlock = isset($capture["amount"]) && is_array($capture["amount"]) ? $capture["amount"] : [];
			$captureAmount      = (int)round($this->toFloat($captureAmountBlock["value"] ?? null) * 100);
			$captureCurrency    = $this->normalizeString($captureAmountBlock["currency_code"] ?? null, $currency);
			
			return match ($captureStatus) {
				// Payment was successfully captured and funds are being transferred.
				"COMPLETED" => new PaymentState(
					provider: self::DRIVER_NAME,
					transactionId: $orderId,
					state: PaymentStatus::Paid,
					currency: $captureCurrency,
					valuePaid: $captureAmount,
					valueRefunded: 0,
					internalState: "COMPLETED",
					metadata: [
						"captureId" => $captureId,
					],
				),
				
				// Capture was declined or voided
				"DECLINED",
				"FAILED" => new PaymentState(
					provider: self::DRIVER_NAME,
					transactionId: $orderId,
					state: PaymentStatus::Failed,
					currency: $captureCurrency,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: $captureStatus,
				),
				
				// PENDING or any unknown status — the capture was submitted but not yet settled
				default => new PaymentState(
					provider: self::DRIVER_NAME,
					transactionId: $orderId,
					state: PaymentStatus::Pending,
					currency: $captureCurrency,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: $captureStatus,
					metadata: [
						"captureId" => $captureId,
					],
				),
			};
		}
		
		/**
		 * Builds a PaymentState for an already-captured order.
		 * Fetches the capture to obtain current refund amounts.
		 * Called from exchange() when order status is COMPLETED.
		 * @see https://developer.paypal.com/docs/api/payments/v2/#captures_get
		 * @param string $orderId The order ID
		 * @param string|null $captureId The capture ID from PaymentState::$metadata['paymentReference']
		 * @param string $internalState
		 * @param string $currency Fallback currency from the order
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		private function buildCompletedPaymentState(string $orderId, ?string $captureId, string $internalState, string $currency): PaymentState {
			if ($captureId === null) {
				throw new PaymentExchangeException(
					self::DRIVER_NAME,
					"MISSING_CAPTURE_ID",
					"Cannot retrieve payment state: captureId is missing from extraData. " .
					"Ensure your payment_exchange listener persists PaymentState::\$metadata['paymentReference'] " .
					"after the first successful exchange. See the refund section in the README."
				);
			}
			
			// Call API to get the capture
			$result = $this->getGateway()->getCapture($captureId);
			
			// If that failed, throw error
			if ($result["request"]["result"] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Grab response — validate it is an array before indexing
			$response           = $result["response"] ?? [];
			$captureStatus      = $this->normalizeString($response["status"] ?? null, $internalState);
			$captureAmountBlock = isset($response["amount"]) && is_array($response["amount"]) ? $response["amount"] : [];
			$capturedAmount     = (int)round($this->toFloat($captureAmountBlock["value"] ?? null) * 100);
			$captureCurrency    = $this->normalizeString($captureAmountBlock["currency_code"] ?? null, $currency);
			$breakdown          = isset($response["seller_receivable_breakdown"]) && is_array($response["seller_receivable_breakdown"]) ? $response["seller_receivable_breakdown"] : [];
			$totalRefunded      = isset($breakdown["total_refunded_amount"]) && is_array($breakdown["total_refunded_amount"]) ? $breakdown["total_refunded_amount"] : [];
			$refundedAmount     = (int)round($this->toFloat($totalRefunded["value"] ?? null) * 100);
			
			// A PENDING capture means PayPal has not yet settled the funds (e-cheque, held funds,
			// manual review, etc.). Do not report this as Paid — the money has not arrived.
			$paymentStatus = match ($captureStatus) {
				"COMPLETED" => PaymentStatus::Paid,
				"DECLINED", "FAILED", "VOIDED" => PaymentStatus::Failed,
				default => PaymentStatus::Pending,
			};
			
			return new PaymentState(
				provider: self::DRIVER_NAME,
				transactionId: $orderId,
				state: $paymentStatus,
				currency: $captureCurrency,
				valuePaid: $paymentStatus === PaymentStatus::Paid ? $capturedAmount : 0,
				valueRefunded: $refundedAmount,
				internalState: $captureStatus,
				metadata: [
					"paymentReference" => $captureId,
				],
			);
		}
		
		/**
		 * Lazily instantiated PayPal gateway.
		 * @return PaypalGateway
		 */
		private function getGateway(): PaypalGateway {
			return $this->gateway ??= new PaypalGateway($this);
		}
	}