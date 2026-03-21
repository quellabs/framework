<?php
	
	namespace Quellabs\Payments\Buckaroo;
	
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
		 * Active configuration for this provider, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var BuckarooGateway|null
		 */
		private ?BuckarooGateway $gateway = null;
		
		/**
		 * Maps our internal module names to Buckaroo's service name strings.
		 * These are passed as Services[].Name in the transaction request.
		 *
		 * The service name must exactly match Buckaroo's documented service code (case matters
		 * in some contexts; lowercase is the canonical form for the JSON API).
		 *
		 * @see https://docs.buckaroo.io/docs/payment-methods
		 */
		private const MODULE_SERVICE_MAP = [
			'bkr_ideal'           => 'ideal',
			'bkr_creditcard'      => 'creditcard',
			'bkr_visa'            => 'visa',
			'bkr_mastercard'      => 'mastercard',
			'bkr_amex'            => 'amex',
			'bkr_bancontact'      => 'bancontactmrcash',
			'bkr_sofort'          => 'sofortueberweisung',
			'bkr_klarna'          => 'klarna',
			'bkr_applepay'        => 'applepay',
			'bkr_paypal'          => 'paypal',
			'bkr_giropay'         => 'giropay',
			'bkr_eps'             => 'eps',
			'bkr_banktransfer'    => 'transfer',
			'bkr_in3'             => 'in3',
			'bkr_afterpay'        => 'afterpay',
			'bkr_sepadirectdebit' => 'sepadirectdebit',
			'bkr_billink'         => 'billink',
		];
		
		// Methods that require or meaningfully use address/shopper data
		private const METHODS_REQUIRING_SHOPPER_DATA = ['bkr_klarna', 'bkr_in3', 'bkr_afterpay', 'bkr_billink'];
		
		/**
		 * Returns discovery metadata for this provider, including all supported payment modules.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => 'buckaroo',
				'modules' => array_keys(self::MODULE_SERVICE_MAP)
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
				'website_key'       => '',
				'secret_key'        => '',
				'return_url'        => '',
				'return_url_cancel' => '',
				'return_url_error'  => '',
				'return_url_reject' => '',
				'webhook_url'       => '',
				'default_culture'   => 'nl-NL',
			];
		}
		
		/**
		 * Returns the normalized issuer list for the given payment module.
		 *
		 * Buckaroo exposes a specification endpoint for iDEAL issuers, but iDEAL issuer
		 * pre-selection was discontinued with iDEAL 2.0 (31-12-2024). Bank selection now
		 * happens exclusively on the hosted payment page. This method always returns an empty array.
		 *
		 * @param string $paymentModule e.g. 'bkr_ideal'
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return [];
		}
		
		/**
		 * Initiates a payment by creating a Buckaroo transaction.
		 *
		 * Buckaroo returns a RequiredAction.RedirectURL to send the shopper to the hosted payment page.
		 * After completion (or cancellation), Buckaroo redirects the shopper back to the configured
		 * return URL with BRQ_TRANSACTIONS (the transaction key) appended as a query parameter.
		 *
		 * Amount conversion: Buckaroo uses decimal floats (e.g. 10.00 for €10.00).
		 * The PaymentRequest carries amounts in minor units (e.g. 1000 for €10.00).
		 * This method divides by 100 — valid for EUR, GBP, USD and most 2-decimal currencies.
		 *
		 * The Invoice field is your order reference (PaymentRequest::$reference). Buckaroo
		 * ties the transaction to this reference; it appears in the return URL as BRQ_INVOICENUMBER.
		 * The Key field in the response is Buckaroo's own transaction identifier.
		 * We store Key as the transactionId so that exchange() and refund() can call back.
		 *
		 * @see https://docs.buckaroo.io/docs/transaction-post
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 * @throws PaymentInitiationException
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			$config = $this->getConfig();
			$service = self::MODULE_SERVICE_MAP[$request->paymentModule] ?? strtolower($request->module);
			
			// Buckaroo uses decimal amounts (€10.00 = 10.00), not minor units (€10.00 = 1000).
			// PaymentRequest::$amount is in minor units, so divide by 100.
			$amount = round($request->amount / 100, 2);
			
			// Build the service parameter list.
			$serviceParams = [];
	
			// Inject billing and shipping addresses
			if (in_array($request->paymentModule, self::METHODS_REQUIRING_SHOPPER_DATA)) {
				// BNPL: inject billing address as BillingCustomer parameters.
				if ($request->billingAddress !== null && in_array($request->paymentModule, self::METHODS_REQUIRING_SHOPPER_DATA)) {
					array_push($serviceParams, ...$this->buildAddressParams($request->billingAddress, 'BillingCustomer'));
				}
				
				// BNPL: inject shipping address as ShippingCustomer parameters.
				// Buckaroo requires this group to be present even when shipping equals billing —
				// fall back to the billing address rather than omitting it and getting a rejection.
				if ($request->shippingAddress !== null) {
					array_push($serviceParams, ...$this->buildAddressParams($request->shippingAddress, 'ShippingCustomer'));
				} elseif ($request->billingAddress !== null) {
					array_push($serviceParams, ...$this->buildAddressParams($request->billingAddress, 'ShippingCustomer'));
				}
			}
			
			$payload = [
				'Currency'        => $request->currency,
				'AmountDebit'     => $amount,
				'Invoice'         => $request->reference,
				'Description'     => $request->description,
				'ReturnURL'       => $config['return_url'],
				'ReturnURLCancel' => $config['return_url_cancel'],
				'ReturnURLError'  => $config['return_url_error'],
				'ReturnURLReject' => $config['return_url_reject'],
				'PushURL'         => $config['webhook_url'],
				'Services'        => [
					'ServiceList' => [
						[
							'Name'       => $service,
							'Action'     => 'Pay',
							'Parameters' => $serviceParams,
						],
					],
				],
			];
			
			// Attach top-level customer fields — valid for all payment methods.
			// CustomerEmail and CustomerCountry feed into fraud scoring and hosted page pre-fill.
			$billingOrShipping = $request->billingAddress ?? $request->shippingAddress;

			if ($billingOrShipping !== null) {
				if (!empty($billingOrShipping->email)) {
					$payload['CustomerEmail'] = $billingOrShipping->email;
				}
				
				if (!empty($billingOrShipping->country)) {
					$payload['CustomerCountry'] = $billingOrShipping->country;
				}
			}
			
			// Call the gateway to create a new transaction
			$result = $this->getGateway()->createTransaction($payload);
			
			// If that failed, throw an exception
			if ($result['request']['result'] === 0) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Grab the API response
			$response = $result['response'];
			
			// Buckaroo's Key is our transactionId going forward — not the invoice number.
			// The redirect URL lives in RequiredAction.RedirectURL.
			$transactionKey = $response['Key'] ?? '';
			$redirectUrl = $response['RequiredAction']['RedirectURL'] ?? '';
			
			// If no transaction id was passed back, throw error
			if (empty($transactionKey)) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], 0, 'Missing transaction Key in Buckaroo response');
			}
			
			// If no redirectUrl was passed back, throw error
			if (empty($redirectUrl)) {
				throw new PaymentInitiationException(self::getMetadata()['driver'], 0, 'Missing RedirectURL in Buckaroo response');
			}
			
			// Return result
			return new InitiateResult(
				provider: self::getMetadata()['driver'],
				transactionId: $transactionKey,
				redirectUrl: $redirectUrl,
			);
		}
		
		/**
		 * Resolves the payment state for a given transaction.
		 *
		 * Buckaroo uses a pull model for both the return URL and push (webhook) flows:
		 * neither the return URL query params nor the push body contains the payment status.
		 * In both cases the authoritative state must be fetched via GET /json/Transaction/Status/{key}.
		 *
		 * Return-URL flow:
		 *   Buckaroo appends BRQ_TRANSACTIONS=<key> (and BRQ_INVOICENUMBER) to the return URL.
		 *   The controller passes action='return' and the key as $transactionId.
		 *
		 * Push flow:
		 *   Buckaroo POSTs a JSON body: { "Transaction": { "Key": "<key>", ... } }.
		 *   The controller passes action='push' and the key as $transactionId.
		 *
		 * Both flows resolve to the same GET /json/Transaction/Status/{key} call.
		 *
		 * Status code mapping:
		 *   190             → Paid
		 *   790/791/792/793 → Pending
		 *   890             → Canceled (cancelled by consumer)
		 *   490/491/492     → Failed
		 *   690             → Failed (rejected by Buckaroo/acquirer)
		 *
		 * Amount note: Buckaroo returns decimal floats. We convert to minor units (*100)
		 * to match the contract's convention.
		 *
		 * @see https://docs.buckaroo.io/docs/integration-status
		 * @param string $transactionId The Buckaroo transaction Key (32-char hex)
		 * @param array $extraData action: 'return' | 'push' (informational; does not change behaviour)
		 * @return PaymentState
		 * @throws PaymentExchangeException
		 */
		public function exchange(string $transactionId, array $extraData = []): PaymentState {
			// Fetch the transaction status from Buckaroo
			$result = $this->getGateway()->getTransactionStatus($transactionId);
			
			// If that faild, throw an exception
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::getMetadata()['driver'], $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Grab response data
			$data = $result['response'];
			$statusCode = (int)($data['Status']['Code']['Code'] ?? 0);
			$currency = $data['Currency'] ?? '';
			
			// Buckaroo distinguishes payment transactions (AmountDebit) from refund transactions
			// (AmountCredit). A refund is a separate transaction with its own Key and its own
			// status 190 — the original transaction is never updated. Checking which field is
			// present is the correct way to identify the transaction type.
			$isRefund = isset($data['AmountCredit']) && !isset($data['AmountDebit']);
			$amountDecimal = (float)($data['AmountDebit'] ?? $data['AmountCredit'] ?? 0);
			$amount = (int)round($amountDecimal * 100);
			
			// Map Buckaroo status codes to our internal PaymentStatus enum.
			// For refund transactions a successful 190 maps to Refunded, not Paid.
			// @see https://docs.buckaroo.io/docs/integration-status
			$state = match (true) {
				$statusCode === 190 && $isRefund => PaymentStatus::Refunded,
				$statusCode === 190 => PaymentStatus::Paid,
				in_array($statusCode, [790, 791, 792, 793]) => PaymentStatus::Pending,
				$statusCode === 890 => PaymentStatus::Canceled,
				in_array($statusCode, [490, 491, 492]) => PaymentStatus::Failed,
				$statusCode === 690 => PaymentStatus::Failed,
				default => PaymentStatus::Pending,
			};
			
			// valuePaid is only set when the transaction is actually paid
			$valuePaid = $state === PaymentStatus::Paid ? $amount : 0;
			$valueRefunded = $state === PaymentStatus::Refunded ? $amount : 0;
			
			// For payment transactions, sum any related refunds.
			// Refund transactions themselves never have further related refunds.
			if (!$isRefund) {
				// Determine the refund value
				$valueRefunded = $this->sumRelatedRefunds($data['RelatedTransactions'] ?? []);
				
				// If the total refunded equals or exceeds the paid amount, the payment is fully
				// refunded. Upgrade the state — Buckaroo never changes the original transaction's
				// status code after a refund, so this is the only way to surface Refunded here.
				if ($valueRefunded >= $valuePaid && $valuePaid > 0) {
					$state = PaymentStatus::Refunded;
				}
			}
			
			return new PaymentState(
				provider: self::getMetadata()['driver'],
				transactionId: $transactionId,
				state: $state,
				currency: $currency,
				valuePaid: $valuePaid,
				valueRefunded: $valueRefunded,
				internalState: (string)$statusCode,
				metadata: array_filter([
					'paymentMethod' => $data['ServiceCode'] ?? null,
					'subCode'       => $data['Status']['SubCode']['Code'] ?? null,
					'subMessage'    => $data['Status']['SubCode']['Description'] ?? null,
					'invoice'       => $data['Invoice'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Refunds a previously completed payment.
		 *
		 * Buckaroo refunds are initiated by POSTing a new transaction with:
		 *   - AmountCredit (decimal) for partial refund, or omitted for full refund
		 *   - OriginalTransactionKey pointing to the original transaction's Key
		 *   - Services.ServiceList[].Action = 'Refund'
		 *
		 * The original service name (e.g. 'ideal') must be included in the refund request.
		 * We derive it from the module map; if the module is unknown we pass 'ideal' as a
		 * safe default for NL merchants (callers should ensure the correct module is set).
		 *
		 * @see https://docs.buckaroo.io/docs/refunds
		 * @param RefundRequest $request
		 * @return RefundResult
		 * @throws PaymentRefundException
		 */
		public function refund(RefundRequest $request): RefundResult {
			// Throw error when the desired paymentModule does not exist in the map
			if (!isset(self::MODULE_SERVICE_MAP[$request->paymentModule])) {
				throw new PaymentRefundException(self::getMetadata()['driver'], 0, 'Unknown payment module: ' . $request->paymentModule);
			}

			// Build payload
			$payload = [
				'Currency'               => $request->currency,
				'Invoice'                => $request->paymentReference . '-refund-' . time(),
				'OriginalTransactionKey' => $request->paymentReference,
				'Services'               => [
					'ServiceList' => [
						[
							'Name'   => self::MODULE_SERVICE_MAP[$request->paymentModule],
							'Action' => 'Refund',
						],
					],
				],
			];
			
			// Only include AmountCredit when a specific partial amount is requested.
			// Buckaroo interprets the absence of AmountCredit as a full refund.
			if ($request->amount !== null) {
				$payload['AmountCredit'] = round($request->amount / 100, 2);
			}
			
			// Add description if passed
			if (!empty($request->description)) {
				$payload['Description'] = $request->description;
			}
			
			// Call the API to issue the refund
			$result = $this->getGateway()->refundTransaction($payload);
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentRefundException(self::getMetadata()['driver'], $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// The refund transaction gets its own Key
			$refundKey = $result['response']['Key'] ?? '';
			
			// AmountCredit in the refund response is the decimal refunded amount
			$refundedDecimal = (float)($result['response']['AmountCredit'] ?? ($request->amount !== null ? $request->amount / 100 : 0));
			$refundedMinor = (int)round($refundedDecimal * 100);
			
			// Return the result
			return new RefundResult(
				provider: self::getMetadata()['driver'],
				paymentReference: $request->paymentReference,
				refundId: $refundKey,
				value: $refundedMinor,
				currency: $request->currency,
			);
		}
		
		/**
		 * Returns a list of RefundResult objects for all refunds associated with a transaction.
		 *
		 * Buckaroo embeds related refund transaction keys in the status response under
		 * RelatedTransactions[].RelationType == 'refund'. This endpoint does NOT include amounts —
		 * we must call getTransactionStatus() on each refund key to retrieve the amount.
		 *
		 * @see https://docs.buckaroo.io/docs/integration-status
		 * @param string $paymentReference The original transaction Key
		 * @return RefundResult[]
		 * @throws PaymentExchangeException
		 */
		public function getRefunds(string $paymentReference): array {
			// Fetch the original transaction to find related refund keys
			$result = $this->getGateway()->getTransactionStatus($paymentReference);
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new PaymentExchangeException(self::getMetadata()['driver'], $result['request']['errorId'], $result['request']['errorMessage']);
			}
			
			// Grab response
			$data = $result['response'];
			$relatedTransactions = $data['RelatedTransactions'] ?? [];

			// Fall back to the original transaction's currency when the refund entry omits it.
			$originalCurrency = $data['Currency'] ?? '';
			$refunds = [];
			
			foreach ($relatedTransactions as $related) {
				// RelatedTransactions also contains chargebacks, payouts, etc. — only process refunds.
				if (strtolower($related['RelationType'] ?? '') !== 'refund') {
					continue;
				}
				
				// Grab related transaction key
				$refundKey = $related['RelatedTransactionKey'] ?? '';
				
				// Malformed entry — skip
				if (empty($refundKey)) {
					continue;
				}
				
				// Buckaroo does not include amounts in RelatedTransactions; fetch each refund
				// transaction individually to get its AmountCredit and Currency.
				$refundResult = $this->getGateway()->getTransactionStatus($refundKey);
				
				// A failed lookup means the refund list would be incomplete — throw rather than
				// return a partial result that could cause incorrect business logic downstream.
				if ($refundResult['request']['result'] === 0) {
					throw new PaymentExchangeException(self::getMetadata()['driver'], $refundResult['request']['errorId'], $refundResult['request']['errorMessage']);
				}
				
				// Add result to list
				$refundData = $refundResult['response'];
				$amountDecimal = (float)($refundData['AmountCredit'] ?? 0);
				
				$refunds[] = new RefundResult(
					provider: self::getMetadata()['driver'],
					paymentReference: $paymentReference,
					refundId: $refundKey,
					value: (int)round($amountDecimal * 100),
					currency: $refundData['Currency'] ?? $originalCurrency,
				);
			}
			
			return $refunds;
		}
		
		/**
		 * Sums the amounts of all related refund transactions.
		 * Returns 0 if no refunds exist or if all lookups fail.
		 * Used internally by exchange() to populate valueRefunded.
		 * @param array $relatedTransactions From the Status response
		 * @return int Total refunded amount in minor units
		 */
		private function sumRelatedRefunds(array $relatedTransactions): int {
			$total = 0;
			
			foreach ($relatedTransactions as $related) {
				// RelatedTransactions also contains chargebacks, payouts, etc. — only sum refunds.
				if (strtolower($related['RelationType'] ?? '') !== 'refund') {
					continue;
				}
				
				// Grab the related transaction key
				$refundKey = $related['RelatedTransactionKey'] ?? '';
				
				// Malformed entry — skip
				if (empty($refundKey)) {
					continue;
				}
				
				// Buckaroo does not include amounts in RelatedTransactions; fetch each refund
				// transaction individually to get its AmountCredit.
				$refundResult = $this->getGateway()->getTransactionStatus($refundKey);
				
				// A failed lookup means valueRefunded would be incorrect — throw rather than
				// return a silently wrong total that could affect payment state transitions.
				if ($refundResult['request']['result'] === 0) {
					throw new PaymentExchangeException(self::getMetadata()['driver'], $refundResult['request']['errorId'], $refundResult['request']['errorMessage']);
				}
				
				// Refund transactions carry AmountCredit (decimal); convert to minor units.
				$amountDecimal = (float)($refundResult['response']['AmountCredit'] ?? 0);
				$total += (int)round($amountDecimal * 100);
			}
			
			return $total;
		}

		/**
		 * Maps a PaymentAddress onto Buckaroo service Parameters entries for the
		 * given group type ('BillingCustomer' or 'ShippingCustomer').
		 * @param PaymentAddress $address
		 * @param string $groupType 'BillingCustomer' or 'ShippingCustomer'
		 * @return array<int, array{Name: string, GroupType: string, GroupID: string, Value: string}>
		 */
		private function buildAddressParams(PaymentAddress $address, string $groupType): array {
			$fields = [
				'Title'             => $address->title,
				'FirstName'         => $address->givenName,
				'LastName'          => $address->familyName,
				'Email'             => $address->email,
				'Phone'             => $address->phone,
				'Street'            => $address->street,
				'HouseNumber'       => $address->houseNumber,
				'HouseNumberSuffix' => $address->houseNumberSuffix,
				'PostalCode'        => $address->postalCode,
				'City'              => $address->city,
				'Country'           => $address->country,
				'Region'            => $address->region,
			];
			
			$params = [];
			
			foreach ($fields as $name => $value) {
				if ($value !== null && $value !== '') {
					$params[] = ['Name' => $name, 'GroupType' => $groupType, 'GroupID' => '', 'Value' => $value];
				}
			}
			
			return $params;
		}

		/**
		 * Lazily instantiates the BuckarooGateway.
		 * @return BuckarooGateway
		 */
		private function getGateway(): BuckarooGateway {
			return $this->gateway ??= new BuckarooGateway($this);
		}
	}