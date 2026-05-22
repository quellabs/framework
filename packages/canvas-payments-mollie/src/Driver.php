<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentAddress;
	use Quellabs\Payments\Contracts\PaymentException;
	use Quellabs\Payments\Contracts\PaymentInterface;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentInitiationException;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Payments\Contracts\RefundResult;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	
	/**
	 * Mollie driver
	 * @phpstan-import-type IssuerOption from PaymentInterface
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 * @phpstan-type RefundData array{
	 *     id: string,
	 *     paymentId: string,
	 *     amount: array{value: string, currency: string}
	 * }
	 */
	class Driver implements PaymentProviderInterface {
		
		use GatewayHelpers;
		
		/**
		 * Driver name
		 */
		const string DRIVER_NAME = "mollie";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
		 */
		private array $config = [];
		
		/**
		 * Maps our internal module names to Mollie's payment method strings.
		 * These are passed as 'method' when creating a payment.
		 * An empty string means no method preference — the customer chooses on Mollie's hosted page.
		 * @see https://docs.mollie.com/reference/v2/payments-api/create-payment
		 */
		private const array MODULE_TYPE_MAP = [
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
		const array MODULES_WITH_ISSUERS = ['mollie_kbc', 'mollie_giftcard'];
		
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
		 * @return array{
		 *     api_key: string,
		 *     test_mode: bool,
		 *     webhook_url: string,
		 *     redirect_url: string,
		 *     cancel_url: string
		 * }
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
			
			// Response data has to be there
			$r             = $response["response"] ?? [];
			$transactionId = $this->normalizeString($r["id"] ?? null);
			$checkoutHref  = $this->normalizeString($this->arrayGet($r, '_links.checkout.href'));
			
			if ($transactionId === '' || $checkoutHref === '') {
				throw new PaymentInitiationException(self::DRIVER_NAME, "204", "Invalid gateway response. Missing id and/or redirect url");
			}
			
			// Extract the hosted checkout URL Mollie generated for this payment
			return new InitiateResult(
				provider: self::DRIVER_NAME,
				transactionId: $transactionId,
				redirectUrl: $checkoutHref
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
			
			// Response resource has to be set
			$r        = $response["response"] ?? [];
			$resource = $this->normalizeString($r["resource"] ?? null);
			
			if ($resource === '') {
				throw new PaymentRefundException(self::DRIVER_NAME, "204", "resource field not set in response");
			}
			
			// Response resource has to be "refund"
			if ($resource !== "refund") {
				throw new PaymentRefundException(self::DRIVER_NAME, "204", "Invalid resource '{$resource}'");
			}
			
			// Validate content of resource
			/** @var array<string, mixed> $amount */
			$amount           = $r["amount"] ?? [];
			$refundId         = $this->normalizeString($r["id"] ?? null);
			$paymentReference = $this->normalizeString($r["paymentId"] ?? null);
			
			if ($refundId === '') {
				throw new PaymentRefundException(self::DRIVER_NAME, "204", "refund id missing from gateway response");
			}
			
			if ($paymentReference === '') {
				throw new PaymentRefundException(self::DRIVER_NAME, "204", "paymentId missing from gateway response");
			}
			
			// Return the data
			return new RefundResult(
				provider: self::DRIVER_NAME,
				paymentReference: $paymentReference,
				refundId: $refundId,
				value: (int)round($this->toFloat($amount["value"] ?? null) * 100),
				currency: $this->normalizeString($amount["currency"] ?? null) ?: $request->currency
			);
		}
		
		/**
		 * Handle payment session updates
		 * @url https://docs.mollie.com/reference/v2/payments-api/get-payment
		 * @url https://docs.mollie.com/payments/webhooks
		 * @param string $transactionId
		 * @param array<string, mixed> $extraData
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
			
			// Fetch response
			$r = $response["response"] ?? [];
			
			// Validate response
			$resource = $this->normalizeString($r["resource"] ?? null);
			
			if ($resource === '') {
				throw new PaymentExchangeException(self::DRIVER_NAME, "204", "resource field not set in response");
			}
			
			if ($resource !== "payment") {
				throw new PaymentExchangeException(self::DRIVER_NAME, "204", "Invalid resource '{$resource}'");
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
			$mollieStatus  = $this->normalizeString($r["status"] ?? null);
			$currentStatus = $stateMap[strtoupper($mollieStatus)] ?? PaymentStatus::Unknown;
			$currency      = $this->normalizeString($this->arrayGet($r, 'amount.currency'));
			
			/** @var array<string, mixed> $amountRefunded */
			$amountRefunded      = $r["amountRefunded"] ?? [];
			$amountRefundedValue = (int)round($this->toFloat($amountRefunded["value"] ?? null) * 100);
			$paidStatuses        = [PaymentStatus::Paid, PaymentStatus::Refunded];
			
			// Determine the value the customer paid
			/** @var array<string, mixed> $amount */
			$amount = $r["amount"] ?? [];
			
			if (in_array($currentStatus, $paidStatuses)) {
				$valuePaid = (int)round($this->toFloat($amount["value"] ?? null) * 100);
			} else {
				$valuePaid = 0;
			}
			
			/** @var array<string, mixed> $metadata */
			$metadata = isset($r["metadata"]) && is_array($r["metadata"]) ? $r["metadata"] : [];
			
			// Return response
			return new PaymentState(
				provider: self::DRIVER_NAME,
				transactionId: $transactionId,
				state: $currentStatus,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $amountRefundedValue,
				internalState: $mollieStatus,
				metadata: $metadata
			);
		}
		
		/**
		 * Returns all refunds for a given transaction
		 * @param string $paymentReference In Mollie's payment model there is no separate capture step,
		 *                                 so this is the payment transaction ID (e.g. tr_7UhSN1zuXS).
		 * @return array<int, RefundResult>
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
			/** @var array<int, RefundData> $responseData */
			$responseData = $response["response"] ?? [];
			
			return array_map(
			/** @param RefundData $refund */
				function (array $refund) use ($paymentReference): RefundResult {
					return new RefundResult(
						provider: self::DRIVER_NAME,
						paymentReference: $paymentReference,
						refundId: $refund["id"],
						value: (int)round((float)$refund["amount"]["value"] * 100),
						currency: $refund["amount"]["currency"],
					);
				},
				$responseData
			);
		}
		
		/**
		 * Returns the issuer list for payment modules that require pre-selection.
		 * KBC requires an issuer to be passed when creating the payment — Mollie returns
		 * an error if it is omitted. Gift cards require an issuer to identify the card brand.
		 * All other modules return an empty array — the hosted page handles all UI.
		 * @see https://docs.mollie.com/reference/v2/methods-api/get-method
		 * @param string $paymentModule e.g. 'mollie_kbc'
		 * @return array<int, IssuerOption>
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
			$methodResponse = $methods["response"] ?? [];
			
			/** @var array<int, array<string, mixed>> $issuers */
			$issuers = is_array($methodResponse["issuers"] ?? null) ? $methodResponse["issuers"] : [];
			
			return array_map(
				/** @param array<string, mixed> $issuer @return IssuerOption */
				function (array $issuer): array {
					/** @var array<string, mixed> $image */
					$image = is_array($issuer["image"] ?? null) ? $issuer["image"] : [];
					
					return [
						'id'       => $this->normalizeString($issuer["id"] ?? null),
						'name'     => $this->normalizeString($issuer["name"] ?? null),
						'issuerId' => $this->normalizeString($issuer["id"] ?? null),
						'swift'    => $this->normalizeString($issuer["id"] ?? null),
						'icon'     => $this->normalizeString($image["size1x"] ?? null),
					];
				},
				$issuers
			);
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
		 * @throws PaymentInitiationException if redirectUrl or cancelUrl cannot be resolved
		 */
		private function resolveUrls(PaymentRequest $request): void {
			// Fetch the configuration data (merged with defaults)
			$config = $this->getConfig();
			
			// Use the request URL if set, otherwise fall back to config — throw if neither is available
			$request->redirectUrl ??= $this->normalizeString($config["redirect_url"] ?? null)
				?: throw new PaymentInitiationException(self::DRIVER_NAME, 500,
					"Mollie payment gateway is misconfigured: 'redirect_url' is missing or empty. " .
					"Set 'redirect_url' in config/mollie.php."
				);
			
			// Use the cancelUrl URL if set, otherwise fall back to config — throw if neither is available
			$request->cancelUrl ??= $this->normalizeString($config["cancel_url"] ?? null)
				?: throw new PaymentInitiationException(self::DRIVER_NAME, 500,
					"Mollie payment gateway is misconfigured: 'cancel_url' is missing or empty. " .
					"Set 'cancel_url' in config/mollie.php."
				);
			
			// webhookUrl is optional — fall back to the default Mollie webhook route
			$request->webhookUrl ??= $this->normalizeString($config["webhook_url"] ?? null) ?: "/webhooks/mollie";
		}
		
		/**
		 * Serializes a PaymentAddress into the array shape Mollie expects
		 * @param PaymentAddress $address
		 * @return array<string, mixed>
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
			], fn($v) => $v !== null);
		}
	}