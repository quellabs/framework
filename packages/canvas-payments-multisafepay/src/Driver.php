<?php
	
	namespace Quellabs\Payments\MultiSafepay;
	
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
		private const MODULE_TYPE_MAP = [
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
				'modules' => array_keys(self::MODULE_TYPE_MAP)
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
		 * For iDEAL, MultiSafepay provides a dedicated GET /issuers/ideal endpoint that returns
		 * the current list of participating banks. The issuer_id must be passed as
		 * gateway_info.issuer_id when creating an iDEAL order.
		 *
		 * For other payment methods that do not use an issuer pre-selection step (e.g. cards,
		 * PayPal), this returns an empty array — the method picker handles all UI on the
		 * hosted payment page.
		 *
		 * @see https://docs.multisafepay.com/reference/issuers
		 * @param string $paymentModule e.g. 'msp_ideal'
		 * @return array
		 * @throws PaymentInitiationException
		 */
		public function getPaymentOptions(string $paymentModule): array {
			// Only iDEAL uses an explicit issuer list; all other methods redirect to the hosted page.
			if ($paymentModule !== 'msp_ideal') {
				return [];
			}
			
			// Fetch issuers
			$result = $this->getGateway()->getIssuers('ideal');
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException('multisafepay', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Return data
			return array_values(array_map(fn($issuer) => [
				'id'       => $issuer['code'],
				'name'     => $issuer['description'],
				'issuerId' => $issuer['code'],
				'swift'    => $issuer['code'],
				
				// MSP does not provide issuer icons in the API response.
				'icon'     => null,
			], $result['response']['data'] ?? []));
		}
		
		/**
		 * Initiates a payment by creating a MultiSafepay order.
		 *
		 * MultiSafepay returns a payment_url that the shopper is redirected to.
		 * After the shopper completes (or abandons) payment, MultiSafepay redirects them
		 * to redirect_url with ?transactionid=<order_id> appended.
		 *
		 * The order type is always 'redirect' — this creates a hosted payment page.
		 * Direct (server-to-server) orders are not handled here.
		 *
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
				throw new PaymentInitiationException('multisafepay', 0, "Unknown payment module: '{$request->paymentModule}'");
			}
			
			// Convert payment module to internal Multisafepay type
			$type = self::MODULE_TYPE_MAP[$request->paymentModule];
			
			// Build order payload.
			// amount is in minor units (e.g. 1250 = €12.50) — same convention as Adyen.
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
				throw new PaymentInitiationException('multisafepay', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Return response
			$response = $result['response']['data'];
			
			return new InitiateResult(
				provider: 'multisafepay',
				transactionId: $response['order_id'],
				redirectUrl: $response['payment_url'],
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
		 * @param array $extraData action: 'return' | 'webhook' (informational; does not change behavior)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Fetch the order
			$result = $this->getGateway()->getOrder($transactionId);
			
			// If that failed, throw an exception
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException('multisafepay', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Determine payment status
			$order = $result['response']['data'] ?? [];
			$status = strtolower($order['status'] ?? '');
			$currency = $order['currency'] ?? '';
			$amount = (int)($order['amount'] ?? 0);
			
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
			$valueRefunded = array_reduce($order['related_transactions'] ?? [], function($carry, $tx) {
				$type = strtolower($tx['type'] ?? '');
				
				if (in_array($type, ['refund', 'partial_refund'], true)) {
					return $carry + ((int)($tx['amount'] ?? 0));
				} else {
					return $carry;
				}
			}, 0);
			
			// Return result
			return new PaymentState(
				provider: 'multisafepay',
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $valueRefunded,
				internalState: $status,
				metadata: array_filter([
					'paymentMethod' => $order['payment_details']['type'] ?? null,
					'reason'        => $order['reason'] ?? null,
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
				throw new PaymentRefundException('multisafepay', $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// MSP returns 'transaction_id' for the refund identifier.
			$refundId = (string)($result['response']['data']['transaction_id'] ?? '');
			
			// Return the refund result
			return new RefundResult(
				provider: 'multisafepay',
				paymentReference: $request->paymentReference,
				refundId: $refundId,
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
				throw new PaymentExchangeException('multisafepay', $result['request']['errorId'], $result['request']['errorMessage']);
			}

			// Grab order data
			$order = $result['response']['data'] ?? [];
			
			// Related transactions are embedded in the order response.
			// Filter to only refund-type transactions; ignore captures, chargebacks, etc.
			$refunds = [];

			foreach ($order['related_transactions'] ?? [] as $related) {
				// Convert type to lowercase
				$relatedType = strtolower($related['type'] ?? '');
				
				// Check if type is a refund
				if (!in_array($relatedType, ['refund', 'partial_refund'], true)) {
					continue;
				}
				
				// If so, add it to the list
				$refunds[] = new RefundResult(
					provider: 'multisafepay',
					paymentReference: $paymentReference,
					refundId: (string)($related['id'] ?? ''),
					value: (int)($related['amount'] ?? 0),
					currency: $related['currency'] ?? $order['currency'] ?? '',
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