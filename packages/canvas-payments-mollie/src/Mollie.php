<?php
	
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Canvas\Kernel;
	use Quellabs\Contracts\Payment\InitiateResponse;
	use Quellabs\Contracts\Payment\PaymentState;
	use Quellabs\Contracts\Payment\PaymentProviderInterface;
	use Quellabs\Contracts\Payment\PaymentRequest;
	use Quellabs\Contracts\Payment\PaymentResponse;
	use Quellabs\Contracts\Payment\PaymentStatus;
	use Quellabs\Contracts\Payment\RefundRequest;
	use Quellabs\Contracts\Payment\RefundResult;
	
	class Mollie implements PaymentProviderInterface {
		
		private Kernel $kernel;
		private MollieGateway $gateway;
		
		/**
		 * Mollie constructor.
		 * @param MollieGateway $mollie
		 */
		public function __construct(Kernel $kernel, MollieGateway $mollie) {
			$this->kernel = $kernel;
			$this->gateway = $mollie;
		}
		
		/**
		 * Returns all modules that are supported by this class
		 * @return array
		 */
		public function getSupportedModules(): array {
			return [
				'mollie',
				'mollie_applepay',
				'mollie_bancontact',
				'mollie_belfius',
				'mollie_creditcard',
				'mollie_eps',
				'mollie_giftcard',
				'mollie_giropay',
				'mollie_ideal',
				'mollie_kbc',
				'mollie_mybank',
				'mollie_paypal',
				'mollie_paysafecard',
				'mollie_przelewy24',
				'mollie_sofort',
				'mollie_billie',
				'mollie_in3',
				'mollie_klarna',
				'mollie_riverty',
			];
		}
		
		/**
		 * Initiate a payment session using Mollie
		 * @url https://docs.mollie.com/reference/v2/payments-api/create-payment
		 * @param PaymentRequest $request
		 * @return PaymentResponse
		 */
		public function initiate(PaymentRequest $request): PaymentResponse {
			// Enhance the request
			$this->resolveUrls($request);
			
			// Initiate the payment
			$paymentMethod = ($request->paymentModule != "mollie") ? substr($request->paymentModule, 7) : "";
			$response = $this->gateway->createPayment($request, $paymentMethod);
			
			// return error if any
			if ($response["request"]["result"] == 0) {
				return PaymentResponse::fail($response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// return formatted response
			return PaymentResponse::ok(new InitiateResponse(
				provider: "mollie",
				transactionId: $response["response"]["id"],
				redirectUrl: $response["response"]["_links"]["checkout"]["href"]
			));
		}
		
		/**
		 * Refund a mollie payment
		 * @param RefundRequest $refundRequest
		 * @return PaymentResponse
		 */
		public function refund(RefundRequest $refundRequest): PaymentResponse {
			// Create the refund
			$response = $this->gateway->createRefund($refundRequest);
			
			// return error in case of error
			if ($response["request"]["result"] == 0) {
				return PaymentResponse::fail($response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// response resource has to be "refund"
			if ($response["response"]["resource"] !== "refund") {
				return PaymentResponse::fail(204, "Invalid resource '{$response["response"]["resource"]}'");
			}
			
			// Return the data
			return PaymentResponse::ok(new RefundResult(
				provider: "mollie",
				transactionId: $response["response"]["paymentId"],
				refundId: $response["response"]["id"],
				value: (float)$response["response"]["amount"]["value"],
				currency: $response["response"]["amount"]["currency"]
			));
		}
		
		/**
		 * Handle payment session updates
		 * @url https://docs.mollie.com/reference/v2/payments-api/get-payment
		 * @url https://docs.mollie.com/payments/webhooks
		 * @param string $transactionId
		 * @param array $extraData
		 * @return PaymentResponse
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentResponse {
			// Fetch payment information from gateway
			$response = $this->gateway->getPaymentInfo($transactionId);
			
			// Return the error if the gateway call failed
			if ($response["request"]["result"] == 0) {
				return PaymentResponse::fail($response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Response resource must be "payment"
			if ($response["response"]["resource"] != "payment") {
				return PaymentResponse::fail(204, "Invalid resource '{$response["response"]["resource"]}'");
			}
			
			// Map Mollie statuses to internal states
			$stateMap = [
				'PENDING'   => PaymentStatus::Pending,
				'OPEN'      => PaymentStatus::Pending,
				'CANCELED'  => PaymentStatus::Canceled,  // Customer canceled — definitive status
				'CANCELLED' => PaymentStatus::Canceled,  // Alternate spelling from Mollie
				'EXPIRED'   => PaymentStatus::Expired,   // Customer abandoned, or bank transfer timed out
				'FAILED'    => PaymentStatus::Failed,    // Cannot be completed with a different payment method
				'PAID'      => PaymentStatus::Paid,
				'REFUNDED'  => PaymentStatus::Refunded,
			];
			
			// Fetch mollie payment status
			$mollieStatus = $response["response"]["status"];
			$currency = $response["response"]["amount"]["currency"];
			$amount = (float)$response["response"]["amount"]["value"];
			$amountRefunded = (float)($response["response"]["amountRefunded"]["value"] ?? 0);
			$amountRefundable = (float)($response["response"]["amountRemaining"]["value"] ?? 0);
			
			// Return response
			return PaymentResponse::ok(new PaymentState(
				provider: 'mollie',
				transactionId: $transactionId,
				state: $stateMap[strtoupper($mollieStatus)] ?? PaymentStatus::Unknown,
				internalState: $mollieStatus,
				valueRequested: $amount,
				valueRefunded: $amountRefunded,
				valueRefundable: $amountRefundable,
				currency: $currency,
				metadata: [
					'description' => $response["response"]["description"],
					'reference'   => $response["response"]["metadata"]["reference"],
				]
			));
		}
		
		/**
		 * Returns all refunds for a given transaction
		 * @param string $transactionId
		 * @return PaymentResponse
		 */
		public function getRefunds(string $transactionId): PaymentResponse {
			// Fetch refunds from Mollie
			$response = $this->gateway->listRefunds($transactionId);
			
			// Return error if the gateway call failed
			if ($response["request"]["result"] == 0) {
				return PaymentResponse::fail($response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Map each refund to a RefundResult
			$refunds = [];
			
			foreach ($response["response"] as $refund) {
				$refunds[] = new RefundResult(
					provider: 'mollie',
					transactionId: $transactionId,
					refundId: $refund["id"],
					value: (float)$refund["amount"]["value"],
					currency: $refund["amount"]["currency"],
				);
			}
			
			return PaymentResponse::ok($refunds);
		}
		
		/**
		 * Returns payment options
		 * @param string $paymentModule
		 * @return PaymentResponse
		 */
		public function getPaymentOptions(string $paymentModule): PaymentResponse {
			switch ($paymentModule) {
				case "mollie_ideal" :
				case "mollie_kbc" :
				case "mollie_giftcard" :
					$methods = $this->gateway->getPaymentMethodInfo(substr($paymentModule, 7));
					
					if ($methods["request"]["result"] == 0) {
						return PaymentResponse::fail(204, "Failed to fetch options for payment method '{$paymentModule}'");
					}
					
					$issuers = [];
					
					if (!empty($methods["response"]["issuers"])) {
						foreach ($methods["response"]["issuers"] as $issuer) {
							$issuers[] = [
								'id'        => $issuer["id"],
								'name'      => $issuer["name"],
								'issuerId'  => $issuer["id"],
								'swift'     => $issuer["id"],
								'icon'      => $issuer["image"]["size1x"],
								'available' => 1
							];
						}
					}
					
					return PaymentResponse::ok($issuers);
				
				default :
					return PaymentResponse::ok([]);
			}
		}
		
		/**
		 * Resolves missing URLs in the payment request from the Mollie config file.
		 * redirectUrl and cancelUrl are required — an exception is thrown if neither
		 * the request nor the config provides them. webhookUrl falls back to a default.
		 * @param PaymentRequest $request
		 * @throws \RuntimeException if redirectUrl or cancelUrl cannot be resolved
		 */
		private function resolveUrls(PaymentRequest $request): void {
			// Load /config/mollie.php
			$config = $this->kernel->loadConfigFile("mollie");
			
			// Use the request URL if set, otherwise fall back to config — throw if neither is available
			$request->redirectUrl ??= $config->get("redirect_url")
				?? throw new \RuntimeException(
					"Mollie payment gateway is misconfigured: 'redirect_url' is missing or empty. " .
					"Set 'redirect_url' in config/mollie.php."
				);
			
			// Use the cancelUrl URL if set, otherwise fall back to config — throw if neither is available
			$request->cancelUrl ??= $config->get("cancel_url")
				?? throw new \RuntimeException(
					"Mollie payment gateway is misconfigured: 'cancel_url' is missing or empty. " .
					"Set 'cancel_url' in config/mollie.php."
				);
			
			// webhookUrl is optional — fall back to the default Mollie webhook route
			$request->webhookUrl ??= $config->get("webhook_url", '/webhooks/mollie');
		}
	}