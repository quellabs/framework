<?php
	
	namespace Quellabs\Payments\Paypal;
	
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
				'driver'  => 'paypal',
				'modules' => ['paypal']
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
			
			// Use the gateway to create a new order
			$result = $this->getGateway()->createOrder(
				$request->amount / 100,
				$request->description,
				$request->currency,
				$config['brand_name'] ?? '',
			);
			
			// If that failed, throw error
			if ($result["request"]["result"] === 0) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Fetch orderId
			$orderId = $result["response"]["id"];
			
			// Extract the HATEOAS approve link — this is the URL we redirect the buyer to.
			// Using the link from the response is more robust than constructing it manually,
			// as PayPal controls its format.
			$approveUrl = null;
			
			foreach ($result["response"]["links"] as $link) {
				if ($link["rel"] === "payer-action") {
					$approveUrl = $link["href"];
					break;
				}
			}
			
			// Validate approveUrl
			if ($approveUrl === null) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], "MISSING_APPROVE_LINK", "PayPal response did not include a payer-action link.");
			}
			
			// Return result
			return new InitiateResult(
				self::getMetadata()['driver'],
				$orderId,
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
		 * @param array  $extraData
		 * @return PaymentState
		 * @throws PaymentExchangeException|PaymentInitiationException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Buyer clicked cancel at PayPal — no payment was attempted.
			// Return a canceled state without querying the API.
			if (($extraData['action'] ?? null) === 'cancel') {
				return new PaymentState(
					provider: self::getMetadata()['driver'],
					transactionId: $transactionId,
					state: PaymentStatus::Canceled,
					currency: "",
					valuePaid: 0,
					valueRefunded: 0,
					internalState: "cancel",
				);
			}
			
			// Fetch the current order state from PayPal
			$order = $this->getGateway()->getOrder($transactionId);
			
			// Validate this went correctly
			if ($order["request"]["result"] === 0) {
				throw new PaymentExchangeException(self::getMetadata()['driver'], $order["request"]["errorId"], $order["request"]["errorMessage"]);
			}
			
			// Map order status to PaymentState
			$orderData    = $order["response"];
			$orderStatus  = $orderData["status"];
			$purchaseUnits = $orderData["purchase_units"] ?? [];
			$purchaseUnit  = $purchaseUnits[0] ?? [];
			$currency     = $purchaseUnit["amount"]["currency_code"] ?? "EUR";
			
			switch ($orderStatus) {
				// Order was created but the buyer hasn't approved it yet (should not normally
				// reach exchange() in this state)
				case "CREATED":
					return new PaymentState(
						provider: self::getMetadata()['driver'],
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
					$captureId = $extraData['paymentReference'] ?? ($purchaseUnit["payments"]["captures"][0]["id"] ?? null);
					return $this->buildCompletedPaymentState($transactionId, $captureId, "COMPLETED", $currency);
				
				// Order was voided. This can mean a genuine cancellation (valueRefunded = 0)
				// or that all captures were fully refunded. Fetch the capture to determine
				// the actual refunded amount rather than assuming zero.
				case "VOIDED":
					$captureId = $extraData['paymentReference'] ?? ($purchaseUnit["payments"]["captures"][0]["id"] ?? null);
					
					// No capture means the order was cancelled before payment — clean cancellation
					if ($captureId === null) {
						return new PaymentState(
							provider: self::getMetadata()['driver'],
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
					
					foreach ($orderData["links"] ?? [] as $link) {
						if ($link["rel"] === "payer-action") {
							$redirectUrl = $link["href"];
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
						provider: self::getMetadata()['driver'],
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
						provider: self::getMetadata()['driver'],
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
			$response = $this->getGateway()->refund(
				$request->paymentReference,
				$value,
				$currency,
				$request->description,
				$idempotencyKey,
			);
			
			// If that failed, throw an exception
			if ($response["request"]["result"] === 0) {
				throw new PaymentRefundException(self::getMetadata()['driver'], $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Send response back to user
			$r = $response["response"];
			
			return new RefundResult(
				provider: self::getMetadata()['driver'],
				paymentReference: $request->paymentReference,
				refundId: $r["id"],
				value: (int)round((float)($r["amount"]["value"] ?? 0) * 100),
				currency: $r["amount"]["currency_code"] ?? $request->currency,
				metadata: [
					"status"       => $r["status"]       ?? null,
					"sellerNote"   => $r["seller_payable_breakdown"]["total_refunded_amount"]["value"] ?? null,
				],
			);
		}
		
		/**
		 * Other payment modules would use this to receive a list of banks or similar.
		 * PayPal does not use it.
		 * @param string $paymentModule
		 * @return array
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
				throw new PaymentRefundException(self::getMetadata()['driver'], $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Flatten the response
			$refunds = [];
			
			foreach ($result["response"] as $refund) {
				$refunds[] = new RefundResult(
					provider: self::getMetadata()['driver'],
					paymentReference: $paymentReference,
					refundId: $refund["id"],
					value: (int)round((float)($refund["amount"]["value"] ?? 0) * 100),
					currency: $refund["amount"]["currency_code"],
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Verifies a PayPal webhook notification by delegating signature validation to the gateway.
		 * @param array  $headers The request headers (lowercased keys)
		 * @param string $rawBody The raw, unmodified request body string
		 * @return bool
		 */
		public function verifyWebhookSignature(array $headers, string $rawBody): bool {
			return $this->getGateway()->verifyWebhookSignature($headers, $rawBody);
		}
		
		/**
		 * Lazily instantiated PayPal gateway.
		 * @return PaypalGateway
		 */
		private function getGateway(): PaypalGateway {
			return $this->gateway ??= new PaypalGateway($this);
		}
		
		/**
		 * Capture payment for an APPROVED order and map the result to a PaymentState.
		 * Called from exchange() when order status is APPROVED.
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		 * @param string $orderId  The order ID
		 * @param string $currency ISO 4217 currency code from the order (used as fallback)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		private function captureOrder(string $orderId, string $currency): PaymentState {
			// Deterministic key derived from the order ID — retrying the same order always
			// produces the same key, so a network timeout cannot cause a double-capture.
			$idempotencyKey = hash('sha256', 'capture:' . $orderId);
			$result = $this->getGateway()->captureOrder($orderId, $idempotencyKey);

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
					
					foreach ($result["response"]["links"] ?? [] as $link) {
						if ($link["rel"] === "payer-action") {
							$redirectUrl = $link["href"];
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
						provider: self::getMetadata()['driver'],
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
				
				throw new PaymentExchangeException(self::getMetadata()['driver'], $errorId, $result["request"]["errorMessage"]);
			}
			
			$purchaseUnits = $result["response"]["purchase_units"] ?? [];
			$captures = $purchaseUnits[0]["payments"]["captures"] ?? [];
			$capture = $captures[0] ?? [];
			$captureId = $capture["id"] ?? null;
			$captureStatus = $capture["status"] ?? "UNKNOWN";
			$captureAmount = (int)round((float)($capture["amount"]["value"] ?? 0) * 100);
			$captureCurrency = $capture["amount"]["currency_code"] ?? $currency;
			
			return match ($captureStatus) {
				// Payment was successfully captured and funds are being transferred.
				"COMPLETED" => new PaymentState(
					provider: self::getMetadata()['driver'],
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
				"FAILED"  => new PaymentState(
					provider: self::getMetadata()['driver'],
					transactionId: $orderId,
					state: PaymentStatus::Failed,
					currency: $captureCurrency,
					valuePaid: 0,
					valueRefunded: 0,
					internalState: $captureStatus,
				),
				
				// PENDING or any unknown status — the capture was submitted but not yet settled
				default => new PaymentState(
					provider: self::getMetadata()['driver'],
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
		 * @param string      $orderId   The order ID
		 * @param string|null $captureId The capture ID from PaymentState::$metadata['paymentReference']
		 * @param string      $internalState
		 * @param string      $currency  Fallback currency from the order
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		private function buildCompletedPaymentState(string $orderId, ?string $captureId, string $internalState, string $currency): PaymentState {
			if ($captureId === null) {
				throw new PaymentExchangeException(
					self::getMetadata()['driver'],
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
				throw new PaymentExchangeException(self::getMetadata()['driver'], $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Grab response
			$c               = $result["response"];
			$captureStatus   = $c["status"] ?? $internalState;
			$capturedAmount  = (int)round((float)($c["amount"]["value"] ?? 0) * 100);
			$refundedAmount  = (int)round((float)($c["seller_receivable_breakdown"]["total_refunded_amount"]["value"] ?? 0) * 100);
			$captureCurrency = $c["amount"]["currency_code"] ?? $currency;
			
			// A PENDING capture means PayPal has not yet settled the funds (e-cheque, held funds,
			// manual review, etc.). Do not report this as Paid — the money has not arrived.
			$paymentStatus = match ($captureStatus) {
				"COMPLETED"                    => PaymentStatus::Paid,
				"DECLINED", "FAILED", "VOIDED" => PaymentStatus::Failed,
				default                        => PaymentStatus::Pending,
			};
			
			return new PaymentState(
				provider: self::getMetadata()['driver'],
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
	}