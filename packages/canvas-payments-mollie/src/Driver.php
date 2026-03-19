<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentException;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentInitiationException;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Contracts\PaymentRefundException;
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
		 * @var MollieGateway|null
		 */
		private ?MollieGateway $gateway = null;
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'modules' => [
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
				],
			];
		}
		
		/**
		 * Returns default configuration values for this provider.
		 * Merged with loaded config files during discovery — values from config files take precedence.
		 * @return array
		 */
		public static function getDefaults(): array {
			return [
				'api_key'      => '',
				'test_mode'    => false,
				'webhook_url'  => 'webhooks/mollie',
				'redirect_url' => 'payment/return/mollie',
				'cancel_url'   => 'payment/cancel/mollie',
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
		 * Initiate a payment session using Mollie
		 * @url https://docs.mollie.com/reference/v2/payments-api/create-payment
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			// Enhance the request
			$this->resolveUrls($request);
			
			// Initiate the payment
			$paymentMethod = ($request->paymentModule != "mollie") ? substr($request->paymentModule, 7) : "";
			$response = $this->getGateway()->createPayment($request, $paymentMethod);
			
			// return error if any
			if ($response["request"]["result"] == 0) {
				throw new PaymentInitiationException("mollie", $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// return formatted response
			return new InitiateResult(
				provider: "mollie",
				transactionId: $response["response"]["id"],
				redirectUrl: $response["response"]["_links"]["checkout"]["href"]
			);
		}
		
		/**
		 * Refund a mollie payment
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Create the refund
			$response = $this->getGateway()->createRefund($request);
			
			// return error in case of error
			if ($response["request"]["result"] == 0) {
				throw new PaymentRefundException("mollie", $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// response resource has to be "refund"
			if ($response["response"]["resource"] !== "refund") {
				throw new PaymentRefundException("mollie", 204, "Invalid resource '{$response["response"]["resource"]}'");
			}
			
			// Return the data
			return new RefundResult(
				provider: "mollie",
				transactionId: $response["response"]["paymentId"],
				refundId: $response["response"]["id"],
				value: (int)round((float)$response["response"]["amount"]["value"] * 100),
				currency: $response["response"]["amount"]["currency"]
			);
		}
		
		/**
		 * Handle payment session updates
		 * @url https://docs.mollie.com/reference/v2/payments-api/get-payment
		 * @url https://docs.mollie.com/payments/webhooks
		 * @param string $transactionId
		 * @param array $extraData
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Fetch payment information from gateway
			$response = $this->getGateway()->getPaymentInfo($transactionId);
			
			// Return the error if the gateway call failed
			if ($response["request"]["result"] == 0) {
				throw new PaymentExchangeException("mollie", $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Response resource must be "payment"
			if ($response["response"]["resource"] != "payment") {
				throw new PaymentExchangeException("mollie", 204, "Invalid resource '{$response["response"]["resource"]}'");
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
			$r                = $response["response"];
			$mollieStatus     = $r["status"];
			$currency         = $r["amount"]["currency"];
			$amountRefunded   = (int)round((float)($r["amountRefunded"]["value"] ?? 0) * 100);
			$amountRefundable = (int)round((float)($r["amountRemaining"]["value"] ?? 0) * 100);
			
			// Return response
			return new PaymentState(
				provider: 'mollie',
				transactionId: $transactionId,
				state: $stateMap[strtoupper($mollieStatus)] ?? PaymentStatus::Unknown,
				valueRefunded: $amountRefunded,
				valueRefundable: $amountRefundable,
				internalState: $mollieStatus,
				currency: $currency,
				metadata: $r["metadata"] ?? []
			);
		}
		
		/**
		 * Returns all refunds for a given transaction
		 * @param string $transactionId
		 * @return array<RefundResult>
		 */
		public function getRefunds(string $transactionId): array {
			// Fetch refunds from Mollie
			$response = $this->getGateway()->listRefunds($transactionId);
			
			// Return error if the gateway call failed
			if ($response["request"]["result"] == 0) {
				throw new PaymentRefundException("mollie", $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Map each refund to a RefundResult
			$refunds = [];
			
			foreach ($response["response"] as $refund) {
				$refunds[] = new RefundResult(
					provider: 'mollie',
					transactionId: $transactionId,
					refundId: $refund["id"],
					value: (int)round((float)$refund["amount"]["value"] * 100),
					currency: $refund["amount"]["currency"],
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Returns payment options
		 * @param string $paymentModule
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array {
			// Only certain methods expose issuer selection (iDEAL banks, KBC, gift cards).
			// Everything else has no options to present.
			$modulesWithIssuers = ['mollie_ideal', 'mollie_kbc', 'mollie_giftcard'];
			
			if (!in_array($paymentModule, $modulesWithIssuers)) {
				return [];
			}
			
			// Strip the 'mollie_' prefix to get the raw method name Mollie expects (e.g. 'ideal')
			// Call the gateway to fetch payment method info
			$methods = $this->getGateway()->getPaymentMethodInfo(substr($paymentModule, 7));
			
			if ($methods["request"]["result"] == 0) {
				throw new PaymentException("mollie", $methods["request"]["errorId"], $methods["request"]["errorMessage"]);
			}
			
			// Flatten the issuer list into a normalized shape for the frontend
			return array_map(fn($issuer) => [
				'id'       => $issuer["id"],
				'name'     => $issuer["name"],
				'issuerId' => $issuer["id"],
				'swift'    => $issuer["id"],
				'icon'     => $issuer["image"]["size1x"],
			], $methods["response"]["issuers"] ?? []);
		}
		
		/**
		 * Lazily instantiated mollie gateway
		 * @return MollieGateway
		 */
		private function getGateway(): MollieGateway {
			return $this->gateway ??= new MollieGateway($this);
		}
		
		/**
		 * Resolves missing URLs in the payment request from the Mollie config file.
		 * redirectUrl and cancelUrl are required — an exception is thrown if neither
		 * the request nor the config provides them. webhookUrl falls back to a default.
		 * @param PaymentRequest $request
		 * @throws \RuntimeException if redirectUrl or cancelUrl cannot be resolved
		 */
		private function resolveUrls(PaymentRequest $request): void {
			// Fetch the configuration data (merged with defaults)
			$config = $this->getConfig();
			
			// Use the request URL if set, otherwise fall back to config — throw if neither is available
			$request->redirectUrl ??= $config["redirect_url"]
				?? throw new PaymentInitiationException("mollie", 500,
					"Mollie payment gateway is misconfigured: 'redirect_url' is missing or empty. " .
					"Set 'redirect_url' in config/mollie.php."
				);
			
			// Use the cancelUrl URL if set, otherwise fall back to config — throw if neither is available
			$request->cancelUrl ??= $config["cancel_url"]
				?? throw new PaymentInitiationException("mollie", 500,
					"Mollie payment gateway is misconfigured: 'cancel_url' is missing or empty. " .
					"Set 'cancel_url' in config/mollie.php."
				);
			
			// webhookUrl is optional — fall back to the default Mollie webhook route
			$request->webhookUrl ??= $config["webhook_url"] ?? "/webhooks/mollie";
		}
	}