<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentAddress;
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
		 * Driver name
		 */
		const DRIVER_NAME = "mollie";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Maps our internal module names to Mollie's payment method strings.
		 * These are passed as 'method' when creating a payment.
		 * An empty string means no method preference — the customer chooses on Mollie's hosted page.
		 * @see https://docs.mollie.com/reference/v2/payments-api/create-payment
		 */
		private const MODULE_TYPE_MAP = [
			'mollie_multi'       => '',
			'mollie_applepay'    => 'applepay',
			'mollie_bancontact'  => 'bancontact',
			'mollie_belfius'     => 'belfius',
			'mollie_creditcard'  => 'creditcard',
			'mollie_eps'         => 'eps',
			'mollie_giftcard'    => 'giftcard',
			'mollie_giropay'     => 'giropay',
			'mollie_ideal'       => 'ideal',
			'mollie_kbc'         => 'kbc',
			'mollie_mybank'      => 'mybank',
			'mollie_paypal'      => 'paypal',
			'mollie_paysafecard' => 'paysafecard',
			'mollie_przelewy24'  => 'przelewy24',
			'mollie_sofort'      => 'sofort',
			'mollie_billie'      => 'billie',
			'mollie_in3'         => 'in3',
			'mollie_klarna'      => 'klarna',
			'mollie_riverty'     => 'riverty',
		];
		
		/**
		 * Modules that require issuer pre-selection before redirecting.
		 * KBC and gift cards need an issuer passed at payment creation — Mollie errors without it.
		 * iDEAL excluded: issuer pre-selection was discontinued with iDEAL 2.0 (31-12-2024).
		 */
		const MODULES_WITH_ISSUERS = ['mollie_kbc', 'mollie_giftcard'];
		
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
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_TYPE_MAP),
			];
		}
		
		/**
		 * Returns default configuration values for this provider.
		 * Merged with loaded config files during discovery — values from config files take precedence.
		 * @return array
		 */
		public function getDefaults(): array {
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
			// Resolve missing redirect/cancel/webhook URLs from config
			$this->resolveUrls($request);
			
			// Map the module name (e.g. 'mollie_ideal') to Mollie's method string (e.g. 'ideal')
			$paymentMethod = self::MODULE_TYPE_MAP[$request->paymentModule];
			
			// Build the Mollie payment payload, stripping null fields before sending
			$payload = array_filter([
				'amount'          => [
					'currency' => $request->currency,
					'value'    => number_format($request->amount / 100, 2, '.', ''),
				],
				'description'     => $request->description,
				'redirectUrl'     => $request->redirectUrl,
				'cancelUrl'       => $request->cancelUrl,
				'webhookUrl'      => $request->webhookUrl,
				'metadata'        => $request->metadata,
				
				// Empty string means no method preference — let the customer choose on Mollie's hosted page
				'method'          => !empty($paymentMethod) ? $paymentMethod : null,
				
				// Only included for methods that require issuer pre-selection (KBC, gift cards).
				// iDEAL no longer accepts an issuer — bank selection moved to the hosted page in iDEAL 2.0.
				'issuer'          => !empty($request->issuerId) ? $request->issuerId : null,
				'billingAddress'  => $request->billingAddress !== null ? $this->serializeAddress($request->billingAddress) : null,
				'shippingAddress' => $request->shippingAddress !== null ? $this->serializeAddress($request->shippingAddress) : null,
				'testmode'        => $this->getGateway()->testMode(),
			], fn($v) => $v !== null);
			
			// Call the API
			$response = $this->getGateway()->createPayment($payload);
			
			// If API call failed, throw error
			if ($response["request"]["result"] == 0) {
				throw new PaymentInitiationException(self::DRIVER_NAME, $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Extract the hosted checkout URL Mollie generated for this payment
			return new InitiateResult(
				provider: self::DRIVER_NAME,
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
			$response = $this->getGateway()->createRefund(
				$request->paymentReference,
				$request->amount,
				$request->currency,
				$request->description,
			);
			
			// Throw error in case of API error
			if ($response["request"]["result"] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Response resource has to be "refund"
			if ($response["response"]["resource"] !== "refund") {
				throw new PaymentRefundException(self::DRIVER_NAME, 204, "Invalid resource '{$response["response"]["resource"]}'");
			}
			
			// Return the data
			return new RefundResult(
				provider: self::DRIVER_NAME,
				paymentReference: $response["response"]["paymentId"],
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
				throw new PaymentExchangeException(self::DRIVER_NAME, $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Response resource must be "payment"
			if ($response["response"]["resource"] != "payment") {
				throw new PaymentExchangeException(self::DRIVER_NAME, 204, "Invalid resource '{$response["response"]["resource"]}'");
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
			$r = $response["response"];
			$mollieStatus = $r["status"];
			$currentStatus = $stateMap[strtoupper($mollieStatus)] ?? PaymentStatus::Unknown;
			$currency = $r["amount"]["currency"];
			$amountRefunded = (int)round((float)($r["amountRefunded"]["value"] ?? 0) * 100);
			$paidStatuses = [PaymentStatus::Paid, PaymentStatus::Refunded];
			
			// Determine the value the customer paid
			if (in_array($currentStatus, $paidStatuses)) {
				$valuePaid = (int)round((float)($r["amount"]["value"] ?? 0) * 100);
			} else {
				$valuePaid = 0;
			}
			
			// Return response
			return new PaymentState(
				provider: self::DRIVER_NAME,
				transactionId: $transactionId,
				state: $currentStatus,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $amountRefunded,
				internalState: $mollieStatus,
				metadata: $r["metadata"] ?? []
			);
		}
		
		/**
		 * Returns all refunds for a given transaction
		 * @param string $paymentReference In Mollie's payment model there is no separate capture step,
		 *                                 so this is the payment transaction ID (e.g. tr_7UhSN1zuXS).
		 * @return array<RefundResult>
		 * @throws PaymentRefundException
		 */
		public function getRefunds(string $paymentReference): array {
			// Fetch refunds from Mollie
			$response = $this->getGateway()->listRefunds($paymentReference);
			
			// Return error if the gateway call failed
			if ($response["request"]["result"] == 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $response["request"]["errorId"], $response["request"]["errorMessage"]);
			}
			
			// Map each refund to a RefundResult
			$refunds = [];
			
			foreach ($response["response"] as $refund) {
				$refunds[] = new RefundResult(
					provider: self::DRIVER_NAME,
					paymentReference: $paymentReference,
					refundId: $refund["id"],
					value: (int)round((float)$refund["amount"]["value"] * 100),
					currency: $refund["amount"]["currency"],
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Returns the issuer list for payment modules that require pre-selection.
		 * KBC requires an issuer to be passed when creating the payment — Mollie returns
		 * an error if it is omitted. Gift cards require an issuer to identify the card brand.
		 * All other modules return an empty array — the hosted page handles all UI.
		 * @see https://docs.mollie.com/reference/v2/methods-api/get-method
		 * @param string $paymentModule e.g. 'mollie_kbc'
		 * @return array
		 * @throws PaymentException
		 */
		public function getPaymentOptions(string $paymentModule): array {
			// Only KBC and gift cards expose issuer selection at the merchant checkout.
			// Everything else redirects to the hosted page with no pre-selection needed.
			if (!in_array($paymentModule, self::MODULES_WITH_ISSUERS)) {
				return [];
			}
			
			// Look up the Mollie method string (e.g. 'kbc') and fetch issuers for it.
			$methods = $this->getGateway()->getPaymentMethodInfo(self::MODULE_TYPE_MAP[$paymentModule]);
			
			// If that failed, throw an error
			if ($methods["request"]["result"] === 0) {
				throw new PaymentException(self::DRIVER_NAME, $methods["request"]["errorId"], $methods["request"]["errorMessage"]);
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
				?? throw new PaymentInitiationException(self::DRIVER_NAME, 500,
					"Mollie payment gateway is misconfigured: 'redirect_url' is missing or empty. " .
					"Set 'redirect_url' in config/mollie.php."
				);
			
			// Use the cancelUrl URL if set, otherwise fall back to config — throw if neither is available
			$request->cancelUrl ??= $config["cancel_url"]
				?? throw new PaymentInitiationException(self::DRIVER_NAME, 500,
					"Mollie payment gateway is misconfigured: 'cancel_url' is missing or empty. " .
					"Set 'cancel_url' in config/mollie.php."
				);
			
			// webhookUrl is optional — fall back to the default Mollie webhook route
			$request->webhookUrl ??= $config["webhook_url"] ?? "/webhooks/mollie";
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
				'streetAndNumber'  => trim($address->street . ' ' . $address->houseNumber . ($address->houseNumberSuffix !== null ? ' ' . $address->houseNumberSuffix : '')),
				'postalCode'       => $address->postalCode,
				'city'             => $address->city,
				'region'           => $address->region,
				'country'          => $address->country,
				'email'            => $address->email,
				'phone'            => $address->phone,
			], [$this, 'notNull']);
		}
	}