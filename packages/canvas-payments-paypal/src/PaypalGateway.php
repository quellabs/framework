<?php
	
	namespace Quellabs\Payments\Paypal;
 
	/**
     * Class PaypalExpress
     * @package Services\Gateways
     * https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECInstantUpdateAPI/
     */
    class PaypalGateway {
        
        protected string|bool|null $m_transaction_server;
        protected string $m_transaction_url;
		private string $m_ipn_url;
        protected string|bool|null $m_api_username;
        protected string|bool|null $m_api_password;
        protected string|bool|null $m_api_signature;
        protected bool $m_verify_ssl;
        protected bool $m_account_optional;
        protected bool $m_test_mode;
		
		/**
         * PaypalExpress constructor.
         */
        public function __construct(Driver $driver) {
            $this->m_test_mode = ($this->config->getConfigurationKey("MODULE_PAYMENT_PAYPAL_EXPRESS_TRANSACTION_SERVER") !== "Live");
            $this->m_transaction_server = $this->config->getConfigurationKey("MODULE_PAYMENT_PAYPAL_EXPRESS_TRANSACTION_SERVER");
            $this->m_transaction_url = $this->m_test_mode ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';
            $this->m_ipn_url = $this->m_test_mode ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' : 'https://ipnpb.paypal.com/cgi-bin/webscr';
            $this->m_api_username = $this->config->getConfigurationKey("MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME");
            $this->m_api_password = $this->config->getConfigurationKey("MODULE_PAYMENT_PAYPAL_EXPRESS_API_PASSWORD");
            $this->m_api_signature = $this->config->getConfigurationKey("MODULE_PAYMENT_PAYPAL_EXPRESS_API_SIGNATURE");
            $this->m_verify_ssl = $this->config->addonEnabled("MODULE_PAYMENT_PAYPAL_EXPRESS_VERIFY_SSL");
            $this->m_account_optional = $this->config->addonEnabled("MODULE_PAYMENT_PAYPAL_EXPRESS_ACCOUNT_OPTIONAL");
        }
		
		/**
		 * Send a transaction to the PayPal server
		 * @param array $parameters
		 * @return array
		 */
		protected function sendTransactionToGatewayHttpClient(array $parameters): array {
			$client = HttpClient::create();
			
			// Stel de transactie-URL samen
			$server = parse_url($this->m_transaction_url);
			$scheme = $server['scheme'] ?? 'http';
			$host = $server['host'] ?? '';
			$path = $server['path'] ?? '/';
			$url = "$scheme://$host$path";
			
			// Voer de HTTP POST-request uit
			try {
				$response = $client->request('POST', $url, [
					'body'        => array_map('trim', $parameters),
					'verify_peer' => $this->m_verify_ssl
				]);
				
				// Verwerk de response
				$content = $response->getContent();
				parse_str($content, $resultArray);
				
				if ($resultArray['ACK'] === 'Failure') {
					return ['request' => ['result' => 0, 'errorId' => $resultArray['L_ERRORCODE0'], 'errorMessage' => $resultArray['L_LONGMESSAGE0']]];
				}
				
				return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => $resultArray];
			} catch (\Exception|TransportExceptionInterface $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
        
        /**
         * Send a transaction to the PayPal server
         * @param array $parameters
         * @return array
         */
        protected function sendTransactionToGateway(array $parameters): array {
            // Set up the transaction url
            $server = parse_url($this->m_transaction_url);
            
            if (!isset($server['port'])) {
                $server['port'] = ($server['scheme'] == 'https') ? 443 : 80;
            }
            
            if (!isset($server['path'])) {
                $server['path'] = '/';
            }
            
            // Transform parameters to an url encoded string
            $postParameters = [];
    
            if (!empty($parameters)) {
                foreach ($parameters as $key => $value) {
                    $postParameters[] = $key . '=' . urlencode(utf8_encode(trim($value)));
                }
            }
            
            // Use curl to call the PayPal API
            $curl = curl_init($server['scheme'] . '://' . $server['host'] . $server['path']);
            curl_setopt($curl, CURLOPT_PORT, $server['port']);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
            curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, implode("&", $postParameters));
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->m_verify_ssl);
            
            if ($this->m_verify_ssl) {
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
                
                if (file_exists($this->config->getBaseFolder() . '/stApp/vendor_st/security/paypal.com.crt')) {
                    curl_setopt($curl, CURLOPT_CAINFO, $this->config->getBaseFolder() . '/stApp/vendor_st/security/paypal.com.crt');
                } elseif (file_exists($this->config->getBaseFolder() . '/stApp/vendor_st/security/cacert.pem')) {
                    curl_setopt($curl, CURLOPT_CAINFO, $this->config->getBaseFolder() . '/stApp/vendor_st/security/cacert.pem');
                }
            }
            
            $result = curl_exec($curl);
            $curl_errno = curl_errno($curl);
            $curl_error = curl_error($curl);
            curl_close($curl);

            // Curl error? report it back to the caller
            if ($curl_errno !== 0) {
                return ['request' => ['result' => 0, 'errorId' => $curl_errno, 'errorMessage' => $curl_error]];
            }

            // Decode the PayPal result.
            // If the ACK is failure, the call failed. Report back the error.
            parse_str($result, $resultArray);

            if ($resultArray["ACK"] === "Failure") {
                return ['request' => ['result' => 0, 'errorId' => $resultArray["L_ERRORCODE0"], 'errorMessage' => $resultArray["L_LONGMESSAGE0"]]];
            }
            
            // Success
            return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ""], 'response' => $resultArray];
        }
        
        /**
         * Return test mode true/false
         * @return mixed
         */
        public function testMode(): bool {
            return $this->m_test_mode;
        }
        
        /**
         * Shows information about an Express Checkout transaction.
         * @url https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
         * @param string $token A timestamped token, the value of which was returned by SetExpressCheckout response.
         * @return array
         */
        public function getExpressCheckoutDetails(string $token): array {
            if (empty($token)) {
                return ['request' => ['result' => 0, 'errorId' => 404, 'errorMessage' => 'Missing token']];
            }
            
            /*
             * returns:
             * CHECKOUTSTATUS / PaymentActionNotInitiated
             *                  PaymentActionFailed
             *                  PaymentActionInProgress
             *                  PaymentActionCompleted
             */
            return $this->sendTransactionToGateway([
                "VERSION"   => 112,
                "METHOD"    => "GetExpressCheckoutDetails",
                "USER"      => $this->m_api_username,
                "PWD"       => $this->m_api_password,
                "SIGNATURE" => $this->m_api_signature,
                "TOKEN"     => $token
            ]);
        }
        
        /**
         * Completes an Express Checkout transaction. If you set up a billing agreement in your
         * SetExpressCheckout API call, the billing agreement is created when you call
         * DoExpressCheckoutPayment.
         * @url https://developer.paypal.com/docs/classic/api/merchant/DoExpressCheckoutPayment_API_Operation_NVP/
         * @param string $transactionId
         * @param float $value
         * @param string $currencyType
         * @param string $payerId
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
                'BUTTONSOURCE'                   => "Shoptrader_EC_NL",
                "PAYMENTREQUEST_0_PAYMENTACTION" => "Sale",
                "PAYMENTREQUEST_0_AMT"           => $value,
                "PAYMENTREQUEST_0_CURRENCYCODE"  => $currencyType
            ]);
        }
    
        /**
         * Initiate a new payment session
         * @url https://developer.paypal.com/docs/archive/express-checkout/ec-initiate-payment/
         * @url https://developer.paypal.com/docs/classic/payflow/express-checkout/sale/#in-context-javascript
         * @param string $emailAddress
         * @param float $value
         * @param string $description
         * @param string $currency
         * @param array $data
         * @return array
         */
        public function setExpressCheckout(string $emailAddress, float $value, string $description, string $currency="EUR", array $data=[]): array {
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
                "PAYMENTREQUEST_0_CURRENCYCODE"  => $currency, // ((Optional)) A 3-character currency code (default is USD).
                "PAYMENTREQUEST_0_AMT"           => $value, // (Required) Total cost of the transaction to the buyer
                "PAYMENTREQUEST_0_DESC"          => $description,
                "RETURNURL"                      => $this->seoHelper->completeUrl("stApp/exchange/paypalHook?action=return"),
                "CANCELURL"                      => $this->seoHelper->completeUrl("stApp/exchange/paypalHook?action=cancel"),
				"NOTIFYURL"                      => $this->seoHelper->completeUrl("stApp/exchange/paypal"),
            ]));
        }
	    
	    /**
	     * Returns transaction details
	     * @param string $transactionId
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
         * Fully refund a transaction
         * @url https://developer.paypal.com/docs/classic/express-checkout/ht_basicRefund-curl-etc/#step-2-refund-the-customer
         * @url https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECRelatedAPIOps/#issuing-refunds
         * @param string $emailAddress
         * @param string $correlationId ID of the transaction for which the refund is made
         * @param string $note
         * @return array
         */
        public function fullRefund(string $correlationId, string $note): array {
            return $this->sendTransactionToGateway([
                "VERSION"       => 94,
                "METHOD"        => "RefundTransaction",
                "USER"          => $this->m_api_username,
                "PWD"           => $this->m_api_password,
                "SIGNATURE"     => $this->m_api_signature,
                "TRANSACTIONID" => $correlationId,
                "REFUNDTYPE"    => "Full",
                "NOTE"          => $note
            ]);
        }
        
        /**
         * Partially refund a transaction
         * @url https://developer.paypal.com/docs/classic/express-checkout/ht_basicRefund-curl-etc/#step-2-refund-the-customer
         * @url https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECRelatedAPIOps/#issuing-refunds
         * @param string $emailAddress
         * @param string $correlationId ID of the transaction for which the refund is made
         * @param float $value
         * @param string $currencyType
         * @param string $note
         * @return array
         */
        public function partialRefund(string $correlationId, float $value, string $currencyType, string $note): array {
            return $this->sendTransactionToGateway([
                "VERSION"       => 94,
                "METHOD"        => "RefundTransaction",
                "USER"          => $this->m_api_username,
                "PWD"           => $this->m_api_password,
                "SIGNATURE"     => $this->m_api_signature,
                "TRANSACTIONID" => $correlationId,
                "REFUNDTYPE"    => "Partial",
                "AMT"           => round($value, 2),
                "CURRENCYCODE"  => $currencyType,
                "NOTE"          => $note
            ]);
        }
	    
	    /**
	     * Search for transactions within a given date range.
	     * @see https://developer.paypal.com/docs/classic/api/merchant/TransactionSearch_API_Operation_NVP/
	     * @param string $startDate UTC date in ISO 8601 format (e.g. "2024-03-01T12:00:00Z")
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
		 * Verifieert PayPal IPN (Instant Payment Notification) berichten.
		 * Deze functie valideert IPN berichten door ze terug te sturen naar PayPal
		 * voor verificatie volgens het PayPal IPN-protocol.
		 * @param array $data De ontvangen IPN-data van PayPal
		 * @return array Gestructureerde response met status en eventuele foutmeldingen
		 */
		public function verifyIpnMessage(array $data): array {
			// Bouw het verificatieverzoek
			$requestData = ['cmd' => '_notify-validate'];
			$requestData += array_map('urlencode', $data);
			
			// Configureer de CURL opties
			$curlOptions = [
				CURLOPT_URL            => $this->m_ipn_url,
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
				CURLOPT_POST           => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POSTFIELDS     => http_build_query($requestData),
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FORBID_REUSE   => true,
				CURLOPT_TIMEOUT        => 30,  // Timeout na 30 seconden
				CURLOPT_HTTPHEADER     => [
					'Connection: Close',
					'User-Agent: PHP-IPN-Verification-Script'
				]
			];
			
			// Initialiseer en voer het CURL-verzoek uit
			$ch = curl_init();
			curl_setopt_array($ch, $curlOptions);
			
			$response = curl_exec($ch);
			$curlError = curl_errno($ch);
			$curlErrorMessage = curl_error($ch);
			curl_close($ch);
			
			// Als er een CURL-error is, return alleen de error informatie
			if ($curlError !== 0) {
				return ['request' => ['result' => 0, 'errorCode' => $curlError, 'errorMessage' => $curlErrorMessage]];
			}
			
			// Als er geen error is, return het complete resultaat
			return ['request' => ['result' => 1, 'errorCode' => '', 'errorMessage' => ''], 'response' => $response];
		}
		
		
	}