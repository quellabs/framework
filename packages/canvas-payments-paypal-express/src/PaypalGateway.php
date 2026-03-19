<?php
	
	namespace Quellabs\Payments\PaypalExpress;
	
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
	use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
	
	/**
	 * Low-level wrapper around the PayPal NVP API.
	 * Handles raw HTTP communication and response parsing.
	 * @see https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECInstantUpdateAPI/
	 */
	class PaypalGateway {
		
		private string $m_transaction_url;
		private string $m_ipn_url;
		private string $m_api_username;
		private string $m_api_password;
		private string $m_api_signature;
		private bool   $m_verify_ssl;
		private bool   $m_account_optional;
		private bool   $m_test_mode;
		private string $m_return_url;
		private string $m_cancel_url;
		private string $m_notify_url;
		
		/**
		 * PaypalGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			$this->m_test_mode        = $config["test_mode"];
			$this->m_transaction_url  = $this->m_test_mode ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';
			$this->m_ipn_url          = $this->m_test_mode ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' : 'https://ipnpb.paypal.com/cgi-bin/webscr';
			$this->m_api_username     = $config["api_username"];
			$this->m_api_password     = $config["api_password"];
			$this->m_api_signature    = $config["api_signature"];
			$this->m_verify_ssl       = $config["verify_ssl"];
			$this->m_account_optional = $config["account_optional"];
			$this->m_return_url       = $config["return_url"];
			$this->m_cancel_url       = $config["cancel_return_url"];
			$this->m_notify_url       = $config["ipn_url"];
		}
		
		
		/**
		 * Return test mode true/false
		 * @return bool
		 */
		public function testMode(): bool {
			return $this->m_test_mode;
		}
		
		/**
		 * Send a request to the PayPal NVP API and return a normalized response array.
		 * All API methods funnel through here to keep HTTP handling in one place.
		 * @param array $parameters NVP key-value pairs to POST to the PayPal API
		 * @return array
		 */
		private function sendTransactionToGateway(array $parameters): array {
			$client = HttpClient::create();
			
			try {
				$response = $client->request('POST', $this->m_transaction_url, [
					'body'        => array_map('trim', $parameters),
					'verify_peer' => $this->m_verify_ssl,
				]);
				
				// PayPal returns URL-encoded NVP pairs — parse them into an associative array
				parse_str($response->getContent(), $resultArray);
				
				// ACK=Failure means the API call was received but rejected — return the first error
				if ($resultArray['ACK'] === 'Failure') {
					return ['request' => ['result' => 0, 'errorId' => $resultArray['L_ERRORCODE0'], 'errorMessage' => $resultArray['L_LONGMESSAGE0']]];
				}
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $resultArray];
			} catch (\Exception|TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				// Network or HTTP-level failure — the request never reached PayPal or couldn't be read
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}

		/**
		 * Shows information about an Express Checkout transaction.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
		 * @param string $token A timestamped token returned by SetExpressCheckout.
		 * @return array
		 */
		public function getExpressCheckoutDetails(string $token): array {
			if (empty($token)) {
				return ['request' => ['result' => 0, 'errorId' => 0, 'errorMessage' => 'Missing token']];
			}
			
			return $this->sendTransactionToGateway([
				"VERSION"   => 112,
				"METHOD"    => "GetExpressCheckoutDetails",
				"USER"      => $this->m_api_username,
				"PWD"       => $this->m_api_password,
				"SIGNATURE" => $this->m_api_signature,
				"TOKEN"     => $token,
			]);
		}
		
		/**
		 * Completes an Express Checkout transaction and captures the payment.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/DoExpressCheckoutPayment_API_Operation_NVP/
		 * @param string $transactionId The checkout token (EC-XXXXXXXXX)
		 * @param float $value Payment amount in major units (e.g. 12.50)
		 * @param string $currencyType ISO 4217 currency code
		 * @param string $payerId The buyer's PayPal account ID
		 * @return array
		 */
		public function doExpressCheckoutPayment(string $transactionId, float $value, string $currencyType, string $payerId): array {
			return $this->sendTransactionToGateway([
				"VERSION"                        => 112,
				"METHOD"                         => "DoExpressCheckoutPayment",
				"USER"                           => $this->m_api_username,
				"PWD"                            => $this->m_api_password,
				"SIGNATURE"                      => $this->m_api_signature,
				"TOKEN"                          => $transactionId,
				"PAYERID"                        => $payerId,
				"PAYMENTREQUEST_0_PAYMENTACTION" => "Sale",
				"PAYMENTREQUEST_0_AMT"           => $value,
				"PAYMENTREQUEST_0_CURRENCYCODE"  => $currencyType,
			]);
		}
		
		/**
		 * Initiates a new Express Checkout session.
		 * @see https://developer.paypal.com/docs/archive/express-checkout/ec-initiate-payment/
		 * @param string $emailAddress Buyer's email address
		 * @param float $value Payment amount in major units (e.g. 12.50)
		 * @param string $description Order description shown on the PayPal checkout page
		 * @param string $currency ISO 4217 currency code
		 * @param array $data Additional NVP parameters to merge into the request
		 * @return array
		 */
		public function setExpressCheckout(string $emailAddress, float $value, string $description, string $currency = "EUR", array $data = []): array {
			return $this->sendTransactionToGateway(array_merge($data, [
				"VERSION"                        => 112,
				"METHOD"                         => "SetExpressCheckout",
				"USER"                           => $this->m_api_username,
				"PWD"                            => $this->m_api_password,
				"SIGNATURE"                      => $this->m_api_signature,
				"SOLUTIONTYPE"                   => $this->m_account_optional ? "Sole" : "Mark",
				"CHANNELTYPE"                    => "Merchant",
				"EMAIL"                          => $emailAddress,
				"PAYMENTREQUEST_0_PAYMENTACTION" => "Sale",
				"PAYMENTREQUEST_0_CURRENCYCODE"  => $currency,
				"PAYMENTREQUEST_0_AMT"           => $value,
				"PAYMENTREQUEST_0_DESC"          => $description,
				"RETURNURL"                      => $this->m_return_url,
				"CANCELURL"                      => $this->m_cancel_url,
				"NOTIFYURL"                      => $this->m_notify_url,
			]));
		}
		
		/**
		 * Returns transaction details for a given payment transaction ID.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/GetTransactionDetails_API_Operation_NVP/
		 * @param string $transactionId The payment transaction ID (PAYMENTINFO_0_TRANSACTIONID)
		 * @return array
		 */
		public function getTransactionDetails(string $transactionId): array {
			return $this->sendTransactionToGateway([
				"VERSION"       => 94,
				"METHOD"        => "GetTransactionDetails",
				"USER"          => $this->m_api_username,
				"PWD"           => $this->m_api_password,
				"SIGNATURE"     => $this->m_api_signature,
				"TRANSACTIONID" => $transactionId,
			]);
		}
		
		/**
		 * Fully refunds a transaction.
		 * @see https://developer.paypal.com/docs/classic/express-checkout/ht_basicRefund-curl-etc/
		 * @param string $transactionId The payment transaction ID to refund
		 * @param string $note Human-readable reason for the refund, shown to the buyer
		 * @return array
		 */
		public function fullRefund(string $transactionId, string $note): array {
			return $this->sendTransactionToGateway([
				"VERSION"       => 94,
				"METHOD"        => "RefundTransaction",
				"USER"          => $this->m_api_username,
				"PWD"           => $this->m_api_password,
				"SIGNATURE"     => $this->m_api_signature,
				"TRANSACTIONID" => $transactionId,
				"REFUNDTYPE"    => "Full",
				"NOTE"          => $note,
			]);
		}
		
		/**
		 * Partially refunds a transaction.
		 * @see https://developer.paypal.com/docs/classic/express-checkout/ht_basicRefund-curl-etc/
		 * @param string $transactionId The payment transaction ID to refund
		 * @param float $value Refund amount in major units (e.g. 12.50)
		 * @param string $currencyType ISO 4217 currency code
		 * @param string $note Human-readable reason for the refund, shown to the buyer
		 * @return array
		 */
		public function partialRefund(string $transactionId, float $value, string $currencyType, string $note): array {
			return $this->sendTransactionToGateway([
				"VERSION"       => 94,
				"METHOD"        => "RefundTransaction",
				"USER"          => $this->m_api_username,
				"PWD"           => $this->m_api_password,
				"SIGNATURE"     => $this->m_api_signature,
				"TRANSACTIONID" => $transactionId,
				"REFUNDTYPE"    => "Partial",
				"AMT"           => round($value, 2),
				"CURRENCYCODE"  => $currencyType,
				"NOTE"          => $note,
			]);
		}
		
		/**
		 * Search for transactions within a given date range, optionally filtered by transaction ID.
		 * @see https://developer.paypal.com/docs/classic/api/merchant/TransactionSearch_API_Operation_NVP/
		 * @param string $startDate UTC date in ISO 8601 format (e.g. "2024-03-01T12:00:00Z")
		 * @param string $transactionId Filter results to transactions related to this payment transaction ID
		 * @param string|null $endDate UTC date in ISO 8601 format, defaults to now if omitted
		 * @return array
		 */
		public function transactionSearch(string $startDate, string $transactionId, ?string $endDate = null): array {
			return $this->sendTransactionToGateway([
				"VERSION"       => 94,
				"METHOD"        => "TransactionSearch",
				"USER"          => $this->m_api_username,
				"PWD"           => $this->m_api_password,
				"SIGNATURE"     => $this->m_api_signature,
				"STARTDATE"     => $startDate,
				"ENDDATE"       => $endDate ?? gmdate("Y-m-d\TH:i:s\Z"),
				"TRANSACTIONID" => $transactionId,
			]);
		}
		
		/**
		 * Verifies a PayPal IPN message by echoing it back to PayPal for validation.
		 * PayPal responds with either "VERIFIED" or "INVALID".
		 * @see https://developer.paypal.com/docs/api-basics/notifications/ipn/IPNIntro/
		 * @param array $data The raw IPN POST data received from PayPal
		 * @return array
		 */
		public function verifyIpnMessage(array $data): array {
			$client = HttpClient::create();
			
			try {
				// Echo the IPN data back to PayPal with the cmd=_notify-validate prefix
				$response = $client->request('POST', $this->m_ipn_url, [
					'headers'     => [
						'Connection' => 'Close',
						'User-Agent' => 'PHP-IPN-Verification-Script',
					],
					'body'        => array_merge(['cmd' => '_notify-validate'], $data),
					'verify_peer' => true,
					'http_version' => '1.1',
				]);
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $response->getContent()];
			} catch (\Exception|TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
	}