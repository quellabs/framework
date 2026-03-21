<?php
	
	namespace Quellabs\Payments\PaypalExpress;
	
	use Quellabs\Payments\Contracts\InitiateResult;
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
				'driver'  => 'paypal_express',
				'modules' => ['paypal_express']
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
				'api_username'      => '',
				'api_password'      => '',
				'api_signature'     => '',
				'brand_name'        => '',
				'account_optional'  => true,
				'verify_ssl'        => true,
				'return_url'        => '',
				'cancel_return_url' => '',
				'ipn_url'           => '',
			];
		}
		
		/**
		 * Initiate a new payment session
		 * @url https://developer.paypal.com/api/nvp-soap/set-express-checkout-nvp/
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$brandName = $this->getConfig()['brand_name'] ?: null;
			$emailAddress = $request->billingAddress?->email ?: null;
			
			// Call gateway
			$result = $this->getGateway()->setExpressCheckout(
				number_format($request->amount / 100, 2),
				$request->description,
				$request->currency,
				array_filter([
					"EMAIL"      => $emailAddress,
					"NOSHIPPING" => 2,
					"ALLOWNOTE"  => 0,
					"BRANDNAME"  => $brandName,
				], fn($v) => $v !== null)
			);
			
			// return error if failed
			if ($result["request"]["result"] === 0) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// transform output
			if ($this->getGateway()->testMode()) {
				$paymentURL = "https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token={$result["response"]["TOKEN"]}&useraction=commit";
			} else {
				$paymentURL = "https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token={$result["response"]["TOKEN"]}&useraction=commit";
			}
			
			return new InitiateResult(
				self::getMetadata()['driver'],
				$result["response"]["TOKEN"],
				$paymentURL,
				[
					"correlationId" => $result["response"]["CORRELATIONID"]
				]
			);
		}
		
		/**
		 * Called when PayPal redirects the buyer back to the return URL.
		 * Fetches the current checkout status and either captures the payment,
		 * or maps the existing state to a PaymentState if already resolved.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
		 * @param string $transactionId The checkout token (EC-XXXXXXXXX) from SetExpressCheckout
		 * @param array $extraData Optional extra data. Accepts 'paymentReference' (the NVP PAYMENTINFO_0_TRANSACTIONID)
		 *                         for already-completed payments to enable refund state retrieval.
		 * @return PaymentState
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Special case: buyer clicked the cancel button at PayPal.
			// No payment was attempted — return a canceled state without querying the API.
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
			
			// Fetch the current checkout status from PayPal using the token.
			$details = $this->getGateway()->getExpressCheckoutDetails($transactionId);
			
			// Throw error when that failed
			if ($details["request"]["result"] === 0) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], $details["request"]["errorId"], $details["request"]["errorMessage"]);
			}
			
			// If we already have the payment transaction ID (e.g. from IPN), the payment is complete.
			// Skip DoExpressCheckoutPayment and go straight to building the completed state.
			if (!empty($extraData['paymentReference'])) {
				return $this->buildCompletedPaymentState(
					$transactionId,
					$extraData['paymentReference'],
					$details["response"]["CHECKOUTSTATUS"],
				);
			}
			
			// Return the correct status
			switch ($details["response"]["CHECKOUTSTATUS"]) {
				// DoExpressCheckoutPayment was called but a response hasn't been received yet.
				// This should be rare in practice.
				case "PaymentActionInProgress":
					return new PaymentState(
						provider: self::getMetadata()['driver'],
						transactionId: $transactionId,
						state: PaymentStatus::Pending,
						currency: $details["response"]["CURRENCYCODE"] ?? "",
						valuePaid: 0,
						valueRefunded: 0,
						internalState: "PaymentActionInProgress",
					);
				
				// DoExpressCheckoutPayment was called but the payment failed.
				case "PaymentActionFailed":
					return new PaymentState(
						provider: self::getMetadata()['driver'],
						transactionId: $transactionId,
						state: PaymentStatus::Failed,
						currency: $details["response"]["CURRENCYCODE"] ?? "",
						valuePaid: 0,
						valueRefunded: 0,
						internalState: "PaymentActionFailed",
					);
				
				// Payment was already captured in a previous exchange() call.
				// Use GetTransactionDetails to retrieve the current monetary state including any refunds.
				case "PaymentActionCompleted":
					return $this->buildCompletedPaymentState(
						$transactionId,
						$extraData['paymentReference'] ?? null,
						"PaymentActionCompleted"
					);
				
				// PaymentActionNotInitiated — buyer has authorized at PayPal but payment hasn't been captured yet.
				// Capture it now via DoExpressCheckoutPayment.
				default:
					return $this->executeCheckoutPayment(
						$transactionId,
						(float)($details["response"]["AMT"] ?? 0),
						$details["response"]["CURRENCYCODE"] ?? "EUR",
						$details["response"]["PAYERID"]
					);
			}
		}
		
		/**
		 * Refund a PayPal payment, either fully or partially.
		 * Note: $request->paymentReference must be the capture ID (the NVP PAYMENTINFO_0_TRANSACTIONID),
		 * not the checkout token. This is available in PaymentState::$metadata['paymentReference']
		 * after a successful exchange() call.
		 * @see https://developer.paypal.com/docs/classic/express-checkout/ht_basicRefund-curl-etc/
		 * @param RefundRequest $request amount=null for a full refund, or a minor-unit integer for a partial refund
		 * @return RefundResult
		 */
		public function refund(RefundRequest $request): RefundResult {
			// A null amount means the caller wants a full refund.
			// PayPal handles the amount calculation internally for full refunds.
			if ($request->amount === null) {
				$response = $this->getGateway()->fullRefund(
					$request->paymentReference,
					$request->description,
				);
			} else {
				// Partial refund — convert from minor units (cents) to major units (e.g. 1050 → 10.50)
				// as required by the PayPal NVP API.
				$response = $this->getGateway()->partialRefund(
					$request->paymentReference,
					$request->amount / 100,
					$request->currency,
					$request->description,
				);
			}
			
			// If that failed through an exception
			if ($response["request"]["result"] === 0) {
				throw new PaymentRefundException(self::getMetadata()['driver'], $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// GROSSREFUNDAMT is returned in major units — convert back to minor units for consistency.
			return new RefundResult(
				provider: self::getMetadata()['driver'],
				paymentReference: $request->paymentReference,
				refundId: $response["response"]["REFUNDTRANSACTIONID"],
				value: (int)round((float)$response["response"]["GROSSREFUNDAMT"] * 100),
				currency: $response["response"]["CURRENCYCODE"],
				metadata: [
					"correlationId" => $response["response"]["CORRELATIONID"],
					"refundStatus"  => $response["response"]["REFUNDSTATUS"],
					"pendingReason" => $response["response"]["PENDINGREASON"],
				],
			);
		}
		
		/**
		 * Other payment modules would use this to receive a list of banks or something.
		 * Paypal express does not use it.
		 * @param string $paymentModule
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Returns all refunds issued for a given payment transaction.
		 * Uses TransactionSearch seeded by the original payment date, then filters
		 * results by type and parent transaction ID.
		 * Note: $transactionId must be the capture ID (the NVP PAYMENTINFO_0_TRANSACTIONID),
		 * not the checkout token.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/TransactionSearch_API_Operation_NVP/
		 * @param string $paymentReference
		 * @return array<RefundResult>
		 */
		public function getRefunds(string $paymentReference): array {
			// Fetch the original payment to get its timestamp, which is required as the
			// search start date. Refunds cannot predate the original payment.
			$txDetails = $this->getGateway()->getTransactionDetails($paymentReference);
			
			if ($txDetails["request"]["result"] === 0) {
				throw new PaymentRefundException(self::getMetadata()['driver'], $txDetails["request"]["errorId"], $txDetails["request"]["errorMessage"]);
			}
			
			// Search for all transactions from the payment date until now
			$search = $this->getGateway()->transactionSearch($txDetails["response"]["ORDERTIME"], $paymentReference);
			
			// If the API call failed, throw an exception
			if ($search["request"]["result"] === 0) {
				throw new PaymentRefundException(self::getMetadata()['driver'], $search["request"]["errorId"], $search["request"]["errorMessage"]);
			}
			
			// Results are returned as flat indexed keys: L_TYPEn, L_TRANSACTIONIDn, etc.
			// Iterate until we run out of results and collect refunds belonging to this transaction.
			$refunds = [];
			$i = 0;
			
			while (isset($search["response"]["L_TRANSACTIONID{$i}"])) {
				if ($search["response"]["L_TYPE{$i}"] === "Refund") {
					$refunds[] = new RefundResult(
						provider: self::getMetadata()['driver'],
						paymentReference: $paymentReference,
						refundId: $search["response"]["L_TRANSACTIONID{$i}"],
						value: (int)round(abs((float)$search["response"]["L_AMT{$i}"]) * 100),
						currency: $search["response"]["L_CURRENCYCODE{$i}"],
					);
				}
				
				++$i;
			}
			
			return $refunds;
		}
		
		/**
		 * Verifies a PayPal IPN message by delegating to the gateway.
		 * @param array $data The raw IPN POST data received from PayPal
		 * @return array
		 */
		public function verifyIpnMessage(array $data): array {
			return $this->getGateway()->verifyIpnMessage($data);
		}
		
		/**
		 * Lazily instantiated PayPal gateway
		 * @return PaypalGateway
		 */
		private function getGateway(): PaypalGateway {
			return $this->gateway ??= new PaypalGateway($this);
		}
		
		/**
		 * Execute a DoExpressCheckoutPayment call and map the result to a PaymentState.
		 * Called from exchange() when CHECKOUTSTATUS is PaymentActionNotInitiated — meaning
		 * the buyer has authorized the payment at PayPal but we haven't captured it yet.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/DoExpressCheckoutPayment_API_Operation_NVP/
		 * @param string $transactionId The checkout token (EC-XXXXXXXXX) from SetExpressCheckout
		 * @param float $amount The payment amount in major units (e.g. 12.50)
		 * @param string $currency ISO 4217 currency code (e.g. 'EUR', 'USD')
		 * @param string $payerId The buyer's PayPal account ID, returned by GetExpressCheckoutDetails
		 * @return PaymentState
		 */
		private function executeCheckoutPayment(string $transactionId, float $amount, string $currency, string $payerId): PaymentState {
			$result = $this->getGateway()->doExpressCheckoutPayment($transactionId, $amount, $currency, $payerId);
			
			if ($result["request"]["result"] === 0) {
				// Error 10486 means the buyer's funding source has insufficient funds.
				// Redirect them back to PayPal to choose a different payment method.
				// @see https://www.paypal-community.com/t5/NVP-SOAP-APIs/PayPal-Error-10486-Decline-recovery-redirect/td-p/1129543
				if ($result["request"]["errorId"] === 10486) {
					if ($this->getGateway()->testMode()) {
						$base = "https://www.sandbox.paypal.com/cgi-bin/webscr";
					} else {
						$base = "https://www.paypal.com/cgi-bin/webscr";
					}
					
					return new PaymentState(
						provider: self::getMetadata()['driver'],
						transactionId: $transactionId,
						state: PaymentStatus::Redirect,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: "10486",
						metadata: [
							"redirectUrl" => "$base?cmd=_express-checkout&token={$transactionId}"
						],
					);
				}
				
				throw new PaymentInitiationException(self::getMetadata()['driver'], $result["request"]["errorId"], $result["request"]["errorMessage"]);
			}
			
			// Convert Paypal status to state object
			$paymentStatus = $result["response"]["PAYMENTINFO_0_PAYMENTSTATUS"];
			$paymentReference = $result["response"]["PAYMENTINFO_0_TRANSACTIONID"];
			$amountMinorUnits = (int)round((float)$result["response"]["PAYMENTINFO_0_AMT"] * 100);
			$currency = $result["response"]["PAYMENTINFO_0_CURRENCYCODE"] ?? $currency;
			
			switch ($paymentStatus) {
				// Payment was accepted and funds have been added to your account balance.
				// Store captureId — it is required for refunds and future status checks.
				case "Processed":
				case "Completed":
				case "Completed-Funds-Held":
					return new PaymentState(
						provider: self::getMetadata()['driver'],
						transactionId: $transactionId,
						state: PaymentStatus::Paid,
						currency: $currency,
						valuePaid: $amountMinorUnits,
						valueRefunded: 0,
						internalState: $paymentStatus,
						metadata: [
							"paymentReference" => $paymentReference,
							"correlationId"    => $result["response"]["CORRELATIONID"],
							"paymentType"      => $result["response"]["PAYMENTINFO_0_PAYMENTTYPE"],
						],
					);
				
				// Payment was declined or voided — no funds were captured.
				case "Failed":
				case "Voided":
					return new PaymentState(
						provider: self::getMetadata()['driver'],
						transactionId: $transactionId,
						state: PaymentStatus::Failed,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: $paymentStatus,
					);
				
				// Any other status (e.g. Pending, Reversed) — treat as pending until resolved.
				default:
					return new PaymentState(
						provider: self::getMetadata()['driver'],
						transactionId: $transactionId,
						state: PaymentStatus::Pending,
						currency: $currency,
						valuePaid: 0,
						valueRefunded: 0,
						internalState: $paymentStatus,
					);
			}
		}
		
		/**
		 * Builds a PaymentState for a payment that has already been captured (CHECKOUTSTATUS=PaymentActionCompleted).
		 * Called from exchange() when the payment was completed in a previous exchange() call.
		 * Uses GetTransactionDetails to retrieve the current refund state, which is not available
		 * from GetExpressCheckoutDetails.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/GetTransactionDetails_API_Operation_NVP/
		 * @param string $token The checkout token (EC-XXXXXXXXX)
		 * @param string|null $captureId The capture ID (NVP PAYMENTINFO_0_TRANSACTIONID) from PaymentState::$metadata['paymentReference'].
		 *                                          Required — throws PaymentInitiationException if null.
		 * @param string $internalState
		 * @return PaymentState
		 */
		private function buildCompletedPaymentState(string $token, ?string $captureId, string $internalState): PaymentState {
			// Throw error when $captureId not passed
			if ($captureId === null) {
				throw new PaymentInitiationException(
					self::getMetadata()['driver'],
					500,
					"Cannot retrieve payment state: captureId is missing from extraData. " .
					"Ensure your payment_exchange listener persists PaymentState::\$metadata['paymentReference'] " .
					"after the first successful payment. See the refund section in the README."
				);
			}
			
			// Fetch the current transaction state from PayPal.
			// GetTransactionDetails is the only NVP call that returns refund amounts.
			$txDetails = $this->getGateway()->getTransactionDetails($captureId);
			
			if ($txDetails["request"]["result"] == 0) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], $txDetails["request"]["errorId"], $txDetails["request"]["errorMessage"]);
			}
			
			// AMT is the original captured amount. TOTALREFUNDEDAMOUNT accumulates across all refunds.
			// Both are returned in major units — convert to minor units for consistency.
			$r = $txDetails["response"];
			$paymentStatus = $r["PAYMENTSTATUS"] ?? $internalState;
			$valueRefunded = (int)round((float)($r["TOTALREFUNDEDAMOUNT"] ?? 0) * 100);
			$valueCaptured = (int)round((float)($r["AMT"] ?? 0) * 100);
			
			// Map NVP PAYMENTSTATUS to PaymentStatus. GetTransactionDetails can return statuses
			// beyond Completed — do not assume Paid without checking.
			$state = match ($paymentStatus) {
				"Processed",
				"Completed",
				"Completed-Funds-Held" => PaymentStatus::Paid,
				"Failed",
				"Voided",
				"Reversed",
				"Canceled-Reversal" => PaymentStatus::Failed,
				default => PaymentStatus::Pending,
			};
			
			return new PaymentState(
				provider: self::getMetadata()['driver'],
				transactionId: $token,
				state: $state,
				currency: $r["CURRENCYCODE"] ?? "",
				valuePaid: $state === PaymentStatus::Paid ? $valueCaptured : 0,
				valueRefunded: $valueRefunded,
				internalState: $paymentStatus,
				metadata: [
					"captureId" => $captureId
				],
			);
		}
	}