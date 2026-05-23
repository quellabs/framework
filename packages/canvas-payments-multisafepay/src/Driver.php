<?php
	
	namespace Quellabs\Payments\MultiSafepay;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
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
	use Quellabs\Payments\Contracts\RefundResult;
	
	/**
	 * Multisafepay driver
	 * @phpstan-import-type IssuerOption from PaymentInterface
	 */
	class Driver implements PaymentProviderInterface {
		
		use GatewayHelpers;
		
		/**
		 * Driver name
		 */
		const string DRIVER_NAME = "multisafepay";
		
		/**
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var MultiSafepayGateway|null
		 */
		private ?MultiSafepayGateway $gateway = null;
		
		/**
		 * Maps our internal module names to MultiSafepay's gateway type strings.
		 * These are passed as 'type' when creating an order.
		 * @see https://docs.multisafepay.com/docs/payment-methods
		 */
		private const array MODULE_TYPE_MAP = [
			'msp_ideal'        => 'IDEAL',
			'msp_creditcard'   => 'CREDITCARD',
			'msp_visa'         => 'VISA',
			'msp_mastercard'   => 'MASTERCARD',
			'msp_amex'         => 'AMEX',
			'msp_bancontact'   => 'MISTERCASH',
			'msp_sofort'       => 'DIRECTBANK',
			'msp_klarna'       => 'KLARNA',
			'msp_applepay'     => 'APPLEPAY',
			'msp_googlepay'    => 'GOOGLEPAY',
			'msp_paypal'       => 'PAYPAL',
			'msp_giropay'      => 'GIROPAY',
			'msp_eps'          => 'EPS',
			'msp_banktransfer' => 'BANKTRANSFER',
			'msp_in3'          => 'IN3',
			'msp_afterpay'     => 'AFTERPAY',
		];
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_TYPE_MAP)
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
		 *
		 * @return array{
		 *     test_mode: bool,
		 *     api_key: string,
		 *     return_url: string,
		 *     cancel_return_url: string,
		 *     notification_url: string,
		 *     default_country: string,
		 *     default_currency: string,
		 *     default_locale: string
		 * }
		 */
		public function getDefaults(): array {
			return [
				'test_mode'         => false,
				'api_key'           => '',
				'return_url'        => '',
				'cancel_return_url' => '',
				'notification_url'  => '',
				'default_country'   => 'NL',
				'default_currency'  => 'EUR',
				'default_locale'    => 'nl_NL',
			];
		}
		
		/**
		 * Returns the normalized issuer list for the given payment module.
		 *
		 * MultiSafepay exposes a GET /issuers/ideal endpoint, but iDEAL issuer pre-selection
		 * was discontinued with iDEAL 2.0 (31-12-2024). Bank selection now happens exclusively
		 * on the hosted payment page. This method always returns an empty array.
		 *
		 * @param string $paymentModule e.g. 'msp_ideal'
		 * @return array<int, IssuerOption>
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Initiates a payment by creating a MultiSafepay order.
		 * @see https://docs.multisafepay.com/reference/createorder
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			// Grab config
			$config = $this->getConfig();
			
			// Resolve the MSP gateway type from the module name.
			if (!isset(self::MODULE_TYPE_MAP[$request->paymentModule])) {
				throw new PaymentInitiationException(self::DRIVER_NAME, 0, "Unknown payment module: '{$request->paymentModule}'");
			}
			
			// Convert payment module to internal Multisafepay type
			$type = self::MODULE_TYPE_MAP[$request->paymentModule];
			
			// Build order payload. Amount is in minor units (e.g. 1250 = €12.50)
			$payload = [
				'type'            => 'redirect',
				'order_id'        => $request->reference,
				'gateway'         => $type,
				'currency'        => $request->currency,
				'amount'          => $request->amount,
				'description'     => $request->description,
				'payment_options' => [
					'notification_url' => $config['notification_url'],
					'redirect_url'     => $config['return_url'],
					'cancel_url'       => $config['cancel_return_url'],
					'close_window'     => false,
				],
			];
			
			// Attach customer block when billing address is present.
			// MSP uses this to pre-fill the hosted page and for fraud scoring.
			if ($request->billingAddress !== null) {
				$payload['customer'] = array_filter([
					'email'   => $request->billingAddress->email ?: null,
					'country' => $request->billingAddress->country ?: null,
					'locale'  => $config['default_locale'],
				], fn($v) => $v !== null);
			}
			
			// Call gateway to create the order
			$result = $this->getGateway()->createOrder($payload);
			
			// If that failed, throw error
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// response['data'] is mixed per array<string, mixed>; narrow to array before use
			$data = $result['response']['data'] ?? null;
			
			if (!is_array($data)) {
				throw new PaymentInitiationException(self::DRIVER_NAME, "500", "Invalid gateway response. Missing data envelope");
			}
			
			// Validate response data is there
			if (!isset($data['order_id']) || !isset($data['payment_url'])) {
				throw new PaymentInitiationException (self::DRIVER_NAME, "500", "Invalid gateway response. Missing id and/or redirect url");
			}
			
			// Return the response
			return new InitiateResult(
				provider: self::DRIVER_NAME,
				transactionId: $this->normalizeString($data['order_id']),
				redirectUrl: $this->normalizeString($data['payment_url']),
			);
		}
		
		/**
		 * Resolves the payment state for a given transaction.
		 *
		 * MultiSafepay uses a pull model for both the return URL and webhook flows:
		 * neither the redirect parameters nor the webhook body contains the payment status.
		 * In both cases the authoritative state must be fetched via GET /orders/{order_id}.
		 *
		 * Return-URL flow:
		 *   MSP appends ?transactionid=<order_id> to redirect_url. The controller passes
		 *   action='return' and the transactionid as $transactionId.
		 *
		 * Webhook flow:
		 *   MSP POSTs a lightweight notification containing the transactionid. The controller
		 *   passes action='webhook' and the transactionid as $transactionId.
		 *   The notification body itself carries no status — we always call the API.
		 *
		 * Because both flows resolve to the same API call, this method is unified.
		 *
		 * @see https://docs.multisafepay.com/reference/getorder
		 * @param string $transactionId The MSP order_id (your reference, returned as transactionid)
		 * @param array<string, mixed> $extraData action: 'return' | 'webhook' (informational; does not change behavior)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Fetch the order
			$result = $this->getGateway()->getOrder($transactionId);
			
			// If that failed, throw an exception
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// response['data'] is mixed per array<string, mixed>; narrow to array before use
			$data = $result['response']['data'] ?? null;
			
			if (!is_array($data)) {
				throw new PaymentExchangeException(self::DRIVER_NAME, "500", "Invalid gateway response. Missing data envelope");
			}
			
			// $data is now array<mixed, mixed>; use helpers to extract typed values safely
			$status   = strtolower($this->normalizeString($data['status'] ?? null));
			$currency = $this->normalizeString($data['currency'] ?? null);
			$amount   = $this->toInt($data['amount'] ?? null);
			
			/** @noinspection PhpDuplicateMatchArmBodyInspection */
			$state = match ($status) {
				'completed' => PaymentStatus::Paid,
				'uncleared' => PaymentStatus::Pending,
				'initialized' => PaymentStatus::Pending,
				'cancelled', 'void', 'expired' => PaymentStatus::Canceled,
				'declined' => PaymentStatus::Failed,
				'refunded' => PaymentStatus::Refunded,
				
				// partial_refunded: the payment itself succeeded; the partial refund is a
				// separate event. We report Paid here — callers should inspect getRefunds()
				// for the refund detail.
				'partial_refunded' => PaymentStatus::Paid,
				
				// chargedback: treat as failed — funds have been reclaimed by the bank.
				'chargedback' => PaymentStatus::Failed,
				
				default => PaymentStatus::Pending,
			};
			
			// valuePaid is the original authorized amount; for refunded orders MSP still
			// reports the original amount in 'amount', so we preserve it.
			$valuePaid = in_array($state, [PaymentStatus::Paid, PaymentStatus::Refunded], true) ? $amount : 0;
			
			// Sum refund-type related_transactions for an accurate valueRefunded.
			// This covers both 'refunded' (full) and 'partial_refunded' (partial) statuses.
			// related_transactions is mixed; narrow to array before iterating.
			$valueRefunded       = 0;
			$relatedTransactions = $data['related_transactions'] ?? null;
			
			if (is_array($relatedTransactions)) {
				foreach ($relatedTransactions as $tx) {
					// Only use array results
					if (!is_array($tx)) {
						continue;
					}

					// Ignore results without a type (should never occur)
					if (!isset($tx['type']) || !is_string($tx['type'])) {
						continue;
					}
					
					// Extract type
					$type = strtolower($tx['type']);
					
					// Only allow
					if (in_array($type, ['refund', 'partial_refund'], true)) {
						$valueRefunded += $this->toInt($tx['amount'] ?? null);
					}
				}
			}
			
			// payment_details is mixed; narrow to array before accessing nested keys
			$paymentDetails = $data['payment_details'] ?? null;
			$paymentMethod  = is_array($paymentDetails) ? $this->normalizeString($paymentDetails['type'] ?? null) : null;
			$reason         = $this->normalizeString($data['reason'] ?? null);
			
			// Return result
			return new PaymentState(
				provider: self::DRIVER_NAME,
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $valueRefunded,
				internalState: $status,
				metadata: array_filter([
					'paymentMethod' => $paymentMethod !== '' ? $paymentMethod : null,
					'reason'        => $reason !== '' ? $reason : null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Refunds a previously completed payment.
		 * @see https://docs.multisafepay.com/reference/refundorder
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Omitting 'amount' triggers a full refund on the MSP side.
			// Only include it when a specific partial amount is requested.
			$payload = array_filter([
				'currency'    => $request->currency,
				'amount'      => $request->amount,
				'description' => $request->description ?? '',
			], fn($v) => $v !== null);
			
			// Call API to issue the refund
			$result = $this->getGateway()->refundOrder($request->paymentReference, $payload);
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Extract and validate response
			$data = $result['response']['data'] ?? null;
			
			if (!is_array($data) || !isset($data['transaction_id']) || !is_string($data['transaction_id'])) {
				throw new PaymentRefundException(self::DRIVER_NAME, "400", "Missing or malformed transaction_id in response");
			}
			
			// Return the refund result
			return new RefundResult(
				provider: self::DRIVER_NAME,
				paymentReference: $request->paymentReference,
				refundId: $data['transaction_id'],
				value: $request->amount,
				currency: $request->currency,
			);
		}
		
		/**
		 * Returns a list of RefundResult objects for all refunds associated with a transaction.
		 * @see https://docs.multisafepay.com/reference/getorder
		 * @param string $paymentReference
		 * @return RefundResult[]
		 * @throws PaymentExchangeException
		 */
		public function getRefunds(string $paymentReference): array {
			// Fetch order info
			$result = $this->getGateway()->getOrder($paymentReference);
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::DRIVER_NAME, $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// response['data'] is mixed per array<string, mixed>; narrow to array before use
			$data = $result['response']['data'] ?? null;
			
			// Validate response data is present
			if (!is_array($data)) {
				throw new PaymentExchangeException(self::DRIVER_NAME, "500", "Invalid gateway response. Missing data envelope");
			}
			
			// Validate response data has relation transactions
			if (!isset($data['related_transactions']) || !is_array($data['related_transactions'])) {
				return [];
			}
			
			// Fallback currency from the parent order, for related transactions that omit it
			$refunds = [];
			$orderCurrency = $this->normalizeString($data['currency'] ?? null);
			
			foreach ($data['related_transactions'] as $related) {
				// Skip non-arrays
				if (!is_array($related)) {
					continue;
				}
				
				// Skip malformed entries without a type
				if (!isset($related['type']) || !is_string($related['type'])) {
					continue;
				}
				
				// Convert type to lowercase
				$relatedType = strtolower($related['type']);
				
				// Check if type is a refund
				if (!in_array($relatedType, ['refund', 'partial_refund'], true)) {
					continue;
				}
				
				// If all pass, add refund to the list
				$refunds[] = new RefundResult(
					provider: self::DRIVER_NAME,
					paymentReference: $paymentReference,
					refundId: $this->normalizeString($related['id'] ?? null),
					value: $this->toInt($related['amount'] ?? null),
					currency: $this->normalizeString($related['currency'] ?? $orderCurrency),
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Lazily instantiates the MultiSafepay gateway.
		 * @return MultiSafepayGateway
		 */
		private function getGateway(): MultiSafepayGateway {
			return $this->gateway ??= new MultiSafepayGateway($this);
		}
	}