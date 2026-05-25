<?php
	
	namespace Quellabs\Shipments\DPD;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	
	/**
	 * Low-level wrapper around the DPD Shipper Webservice SOAP API (NL).
	 * Handles raw HTTP communication, authentication token management,
	 * label file caching, and XML request/response serialisation.
	 *
	 * Authentication:
	 *   DPD uses a 24-hour auth token obtained via the Login Service (getAuth).
	 *   The token is valid for exactly 24 hours and must be cached — DPD permits
	 *   a maximum of 10 login calls per day. This gateway caches the token and
	 *   expiry timestamp in-memory and re-authenticates only when the token has
	 *   expired or is absent.
	 *
	 *   Stage and live environments use separate credentials. The token from one
	 *   environment is not valid in the other.
	 *
	 * Protocol:
	 *   All services use SOAP 1.1 over HTTPS. Requests are raw XML strings;
	 *   responses are parsed with SimpleXML. The SoapClient extension is NOT used
	 *   because the DPD WSDL defines complex namespace structures that cause
	 *   SoapClient to generate incorrect envelopes for the authentication header.
	 *
	 * Label caching:
	 *   DPD does not provide a label re-fetch endpoint. The label PDF is returned
	 *   as base64 in the creation response and written to a file cache keyed by
	 *   parcel label number. Files are stored in config['cache_path'] (resolved
	 *   relative to the project root; path resolution is handled by LabelFileCache).
	 *   Stale files older than config['label_cache_ttl_days'] are purged
	 *   opportunistically on each read and write.
	 *
	 * Response normalisation:
	 *   All public methods return the same envelope as the other drivers:
	 *   ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => <data>]
	 *   ['request' => ['result' => 0, 'errorId' => <code>, 'errorMessage' => <msg>]]
	 *
	 * WSDLs:
	 *   Login:         Live: https://wsshipper.dpd.nl/soap/WSDL/LoginServiceV21.wsdl
	 *   Shipment:      Live: https://wsshipper.dpd.nl/soap/WSDL/ShipmentServiceV35.wsdl
	 *   ParcelShop:    Live: https://wsshipper.dpd.nl/soap/WSDL/ParcelShopFinderServiceV50.wsdl
	 *   ParcelLifeCycle: Live: https://wsshipper.dpd.nl/soap/WSDL/ParcelLifecycleServiceV20.wsdl
	 *
	 * @see https://integrations.dpd.nl/dpd-shipper/dpd-shipper-webservices/
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class DPDGateway {
		
		use GatewayHelpers;
		
		/** Live base URL for all DPD Shipper Webservice endpoints */
		private const string BASE_URL_LIVE = 'https://wsshipper.dpd.nl/soap/services';
		
		/** Stage base URL for all DPD Shipper Webservice endpoints */
		private const string BASE_URL_TEST = 'https://shipperadmintest.dpd.nl/PublicAPI/soap/services';
		
		/** @var HttpClientInterface */
		private HttpClientInterface $client;
		
		/** @var string Resolved base URL */
		private string $baseUrl;
		
		/** @var string DPD Delis ID credential */
		private string $delisId;
		
		/** @var string DPD password credential */
		private string $password;
		
		/** @var string|null Cached auth token */
		private ?string $authToken = null;
		
		/** @var \DateTimeImmutable|null Expiry of the cached auth token */
		private ?\DateTimeImmutable $authTokenExpires = null;
		
		/** @var LabelFileCache Persists label PDFs across requests */
		private LabelFileCache $labelCache;
		
		/**
		 * DPDGateway constructor.
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$config = $driver->getConfig();
			
			// getConfig() merges getDefaults() first, so these keys are guaranteed to
			// exist with the correct types. We still guard here to satisfy PHPStan level 9.
			$isTest = $this->toBool($config['test_mode'] ?? null);
			
			$this->baseUrl    = $isTest ? self::BASE_URL_TEST : self::BASE_URL_LIVE;
			$this->delisId    = $this->normalizeString($isTest ? ($config['test_delis_id'] ?? null) : ($config['delis_id'] ?? null));
			$this->password   = $this->normalizeString($isTest ? ($config['test_password'] ?? null) : ($config['password'] ?? null));
			$this->labelCache = new LabelFileCache(
				$this->normalizeString($config['cache_path'] ?? null, 'storage/dpd/labels'),
				$this->toInt($config['label_cache_ttl_days'] ?? null, 30),
			);
			
			$this->client = HttpClient::create(['timeout' => 30]);
		}
		
		/**
		 * Creates a shipment via the DPD Shipment Service (storeOrders).
		 *
		 * The response contains the parcel label number (14-digit barcode) and a
		 * base64-encoded PDF label. The label is written to the file cache so that
		 * getLabel() can retrieve it in any subsequent request or process.
		 *
		 * @param array<string, mixed> $payload Structured shipment data (generalShipmentData, parcels, productAndServiceData)
		 * @return GatewayResponse
		 */
		public function createShipment(array $payload): array {
			// Unpack payload sections — each must be an array; guard before typed method calls
			$general = $payload['generalShipmentData'];
			$parcels = $payload['parcels'];
			$psd     = $payload['productAndServiceData'];
			
			if (!is_array($general) || !is_array($parcels) || !is_array($psd)) {
				return ['request' => ['result' => 0, 'errorId' => 'invalid_payload', 'errorMessage' => 'Payload sections must be arrays']];
			}
			
			$sendingDepot = is_string($general['sendingDepot'] ?? null) ? $general['sendingDepot'] : '';
			$product      = is_string($general['product'] ?? null) ? $general['product'] : '';
			$sender       = is_array($general['sender'] ?? null) ? $general['sender'] : [];
			$recipient    = is_array($general['recipient'] ?? null) ? $general['recipient'] : [];
			
			// Build XML fragments for each section
			$senderXml    = $this->buildAddressXml('sender', $sender);
			$recipientXml = $this->buildAddressXml('recipient', $recipient);
			$parcelsXml   = $this->buildParcelsXml($parcels);
			$psdXml       = $this->buildProductAndServiceDataXml($psd);
			
			$xml = $this->buildEnvelope(
				'http://dpd.com/common/service/types/ShipmentService/3.5',
				<<<XML
    <ns1:storeOrders>
      <printOptions>
        <printerLanguage>PDF</printerLanguage>
        <paperFormat>A6</paperFormat>
      </printOptions>
      <order>
        <generalShipmentData>
          <sendingDepot>{$this->xmlEscape($sendingDepot)}</sendingDepot>
          <product>{$this->xmlEscape($product)}</product>
          {$senderXml}
          {$recipientXml}
        </generalShipmentData>
        <parcels>
          {$parcelsXml}
        </parcels>
        <productAndServiceData>
          {$psdXml}
        </productAndServiceData>
      </order>
    </ns1:storeOrders>
XML
			);
			
			// Send request; return immediately on auth or transport failure
			$result = $this->request('/ShipmentService', $xml);
			
			if ($result['request']['result'] === 0) {
				return $result;
			}
			
			// Parse and return the shipment response
			$response = $result['response'] ?? [];
			
			if (!is_array($response) || !is_string($response['body'] ?? null) || !is_int($response['statusCode'] ?? null)) {
				return ['request' => ['result' => 0, 'errorId' => 'invalid_response', 'errorMessage' => 'DPD returned an unexpected response structure']];
			}
			
			return $this->parseShipmentResponse($response['body'], $response['statusCode']);
		}
		
		/**
		 * Fetches tracking data for a parcel via the ParcelLifeCycle Service.
		 * @param string $parcelLabelNumber 14-digit DPD barcode
		 * @return GatewayResponse
		 */
		public function getTrackingData(string $parcelLabelNumber): array {
			$xml = $this->buildEnvelope(
				'http://dpd.com/common/service/types/ParcelLifeCycleService/2.0',
				<<<XML
    <ns1:getTrackingData>
      <parcelLabelNumber>{$this->xmlEscape($parcelLabelNumber)}</parcelLabelNumber>
    </ns1:getTrackingData>
XML
			);
			
			// Send request; return immediately on auth or transport failure
			$result = $this->request('/ParcelLifeCycleService', $xml);
			
			if ($result['request']['result'] === 0) {
				return $result;
			}
			
			// Parse and return the tracking response
			$response = $result['response'] ?? [];
			
			if (!is_array($response) || !is_string($response['body'] ?? null) || !is_int($response['statusCode'] ?? null)) {
				return ['request' => ['result' => 0, 'errorId' => 'invalid_response', 'errorMessage' => 'DPD returned an unexpected response structure']];
			}
			
			return $this->parseTrackingResponse($response['body'], $response['statusCode']);
		}
		
		/**
		 * Finds nearby DPD parcel shops by postal code, country and city.
		 * @param string $country ISO 3166-1 alpha-2
		 * @param string $postalCode
		 * @param string $city
		 * @param int $limit Max results (server-side cap: 100)
		 * @return GatewayResponse
		 */
		public function findParcelShops(string $country, string $postalCode, string $city, int $limit = 10): array {
			$xml = $this->buildEnvelope(
				'http://dpd.com/common/service/types/ParcelShopFinderService/5.0',
				<<<XML
    <ns1:findParcelShops>
      <country>{$this->xmlEscape($country)}</country>
      <zipCode>{$this->xmlEscape($postalCode)}</zipCode>
      <city>{$this->xmlEscape($city)}</city>
      <limit>{$limit}</limit>
    </ns1:findParcelShops>
XML
			);
			
			// Send request; return immediately on auth or transport failure
			$result = $this->request('/ParcelShopFinderService', $xml);
			
			if ($result['request']['result'] === 0) {
				return $result;
			}
			
			// Parse and return the parcel shop response
			$response = $result['response'] ?? [];
			
			if (!is_array($response) || !is_string($response['body'] ?? null) || !is_int($response['statusCode'] ?? null)) {
				return ['request' => ['result' => 0, 'errorId' => 'invalid_response', 'errorMessage' => 'DPD returned an unexpected response structure']];
			}
			
			return $this->parseParcelShopResponse($response['body'], $response['statusCode']);
		}
		
		/**
		 * Returns the base64 label content for a given parcel label number.
		 *
		 * Reads from the file cache written by createShipment(). Works across
		 * requests and processes as long as the cache file has not expired.
		 * Purges stale files opportunistically on each call.
		 *
		 * @param string $parcelLabelNumber
		 * @return GatewayResponse
		 */
		public function getLabel(string $parcelLabelNumber): array {
			$content = $this->labelCache->read($parcelLabelNumber);
			
			if ($content === null) {
				return [
					'request' => [
						'result'       => 0,
						'errorId'      => 'label_not_cached',
						'errorMessage' => "Label for parcel {$parcelLabelNumber} is not in the file cache. "
							. "DPD does not provide a label re-fetch endpoint. "
							. "The cache file may have expired or create() was never called for this parcel.",
					],
				];
			}
			
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => ['labelContent' => $content],
			];
		}
		
		/**
		 * Returns a valid DPD auth token, re-authenticating only when necessary.
		 * Returns null if authentication fails.
		 *
		 * The token is stored in \$this->authToken and \$this->authTokenExpires on this
		 * gateway instance. The gateway itself is held in Driver::\$gateway via lazy
		 * instantiation (??=), so the cache lives as long as the Driver instance —
		 * typically one PHP request or one queue job.
		 *
		 * For long-running processes (daemons, workers) that outlive a single request,
		 * the Driver is re-instantiated per job and the token is re-fetched on the first
		 * call of each job. This is within DPD's 10-calls-per-day limit as long as you
		 * don't spawn more than 10 worker processes per day against the same account.
		 * If you anticipate running more than 10 worker processes per day, contact
		 * DPD to discuss extended API limits.
		 *
		 * A 5-minute safety margin is applied so the token is refreshed before it
		 * actually expires, avoiding races on slow networks or long-running requests.
		 *
		 * @return string|null
		 */
		private function getValidToken(): ?string {
			$now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$margin = new \DateInterval('PT5M'); // 5-minute safety margin
			
			try {
				if (
					$this->authToken !== null &&
					$this->authTokenExpires !== null &&
					$this->authTokenExpires->sub($margin) > $now
				) {
					return $this->authToken;
				}
			} catch (\DateInvalidOperationException) {
				// authTokenExpires somehow holds an invalid state; fall through and re-authenticate
				$this->authToken        = null;
				$this->authTokenExpires = null;
			}
			
			return $this->authenticate();
		}
		
		/**
		 * Calls the DPD Login Service and caches the resulting token.
		 * @return string|null
		 */
		private function authenticate(): ?string {
			$xml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="http://dpd.com/common/service/types/LoginService/2.0">
  <soapenv:Header/>
  <soapenv:Body>
    <ns:getAuth>
      <delisId>{$this->xmlEscape($this->delisId)}</delisId>
      <password>{$this->xmlEscape($this->password)}</password>
      <messageLanguage>en_EN</messageLanguage>
    </ns:getAuth>
  </soapenv:Body>
</soapenv:Envelope>
XML;
			
			try {
				// Call the DPD Login Service
				$response = $this->client->request('POST', $this->baseUrl . '/LoginService', [
					'headers' => [
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '""',
					],
					'body'    => $xml,
				]);
				
				// Parse the response body
				$body = $response->getContent(false);
				$xml  = $this->parseXml($body);
				
				if ($xml === null) {
					return null;
				}
				
				// Extract the return element from the response
				$xml->registerXPathNamespace('ns', 'http://dpd.com/common/service/types/LoginService/2.1');
				$result = $xml->xpath('//ns:return')[0] ?? null;
				
				if ($result === null) {
					return null;
				}
				
				// Extract token and expiry from the return element
				$token   = (string)($result->authToken ?? '');
				$expires = (string)($result->authTokenExpires ?? '');
				
				if (empty($token)) {
					return null;
				}
				
				// Cache the token
				$this->authToken = $token;
				
				// Parse expiry timestamp — DPD returns UTC datetime e.g. '2020-05-08T13:02:56.06'
				// Both createFromFormat() calls return false on failure instead of throwing.
				// The 'U' format accepts any integer Unix timestamp string and never fails.
				$utc      = new \DateTimeZone('UTC');
				$parsed   = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $expires, $utc);
				$fallback = \DateTimeImmutable::createFromFormat('U', (string)(time() + 23 * 3600));
				
				if ($parsed !== false) {
					$this->authTokenExpires = $parsed;
				} elseif ($fallback !== false) {
					$this->authTokenExpires = $fallback;
				} else {
					$this->authTokenExpires = null;
				}
				
				return $this->authToken;
			} catch (ExceptionInterface) {
				return null;
			}
		}
		
		/**
		 * Acquires a valid auth token, sends a raw SOAP request, and returns a
		 * normalised GatewayResponse. On success, 'response' contains 'statusCode'
		 * and 'body' for the caller to parse. On auth or transport failure, returns
		 * a standard error envelope.
		 * @param string $endpoint Service path appended to the base URL (e.g. '/ShipmentService')
		 * @param string $xml SOAP envelope with {{token}} and {{delisId}} placeholders
		 * @return GatewayResponse
		 */
		private function request(string $endpoint, string $xml): array {
			// Fetch the access token
			$token = $this->getValidToken();
			
			// If no token, return failure response
			if ($token === null) {
				return ['request' => ['result' => 0, 'errorId' => 'auth_failed', 'errorMessage' => 'DPD authentication failed']];
			}
			
			// Copy token into XML data
			$xml = str_replace(['{{token}}', '{{delisId}}'], [$token, $this->delisId], $xml);
			
			try {
				// Call API
				$response = $this->client->request('POST', $this->baseUrl . $endpoint, [
					'headers' => [
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '""',
					],
					'body'    => $xml,
				]);
				
				// Return response
				return [
					'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
					'response' => [
						'statusCode' => $response->getStatusCode(),
						'body'       => $response->getContent(false)
					]
				];
			} catch (ExceptionInterface $e) {
				return ['request' => ['result' => 0, 'errorId' => (string)$e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Returns a standard error envelope if the XML contains a SOAP fault, null otherwise.
		 * @param \SimpleXMLElement $xml
		 * @return GatewayResponse|null
		 */
		private function soapFaultResponse(\SimpleXMLElement $xml): ?array {
			$fault = $xml->xpath('//faultstring');
			return !empty($fault) ? ['request' => ['result' => 0, 'errorId' => 'soap_fault', 'errorMessage' => (string)$fault[0]]] : null;
		}
		
		/**
		 * Parses a SOAP storeOrders response and extracts the parcel label number and label content.
		 * @param string $body Raw SOAP response body
		 * @param int $statusCode HTTP status code
		 * @return GatewayResponse
		 */
		private function parseShipmentResponse(string $body, int $statusCode): array {
			// validate status code
			if ($statusCode >= 400) {
				return $this->parseErrorResponse($body, $statusCode);
			}
			
			// Parse XML
			$xml = $this->parseXml($body);
			
			// If that failed, return error
			if ($xml === null) {
				return ['request' => ['result' => 0, 'errorId' => 'parse_error', 'errorMessage' => 'Failed to parse DPD shipment response']];
			}
			
			// Check for fault
			if ($fault = $this->soapFaultResponse($xml)) {
				return $fault;
			}
			
			// Look for error in response
			$xml->registerXPathNamespace('ns1', 'http://dpd.com/common/service/types/ShipmentService/3.5');
			$response = $xml->xpath('//ns1:storeOrdersResponse/return')[0] ?? null;
			
			// Try without namespace
			if ($response === null) {
				$response = $xml->xpath('//*[local-name()="return"]')[0] ?? null;
			}
			
			if ($response === null) {
				return ['request' => ['result' => 0, 'errorId' => 'empty_response', 'errorMessage' => 'DPD returned an empty shipment response']];
			}
			
			// Check for fault in the response body
			$faultCode    = (string)($response->shipmentFaultCode ?? '');
			$faultMessage = (string)($response->shipmentFaultDescription ?? '');
			
			if (!empty($faultCode)) {
				return ['request' => ['result' => 0, 'errorId' => $faultCode, 'errorMessage' => $faultMessage ?: "DPD fault: {$faultCode}"]];
			}
			
			$parcelLabelNumber = (string)($response->parcelLabelNumber ?? '');
			
			// Label PDF is in the parcels/label/content node (field: parcellabelsPDF)
			$labelNode  = $xml->xpath('//*[local-name()="parcels"]/*[local-name()="label"]/*[local-name()="content"]');
			$pdfContent = !empty($labelNode) ? (string)$labelNode[0] : null;
			
			// Persist label to file cache so getLabelUrl() can retrieve it across requests
			if ($parcelLabelNumber !== '' && $pdfContent !== null) {
				$this->labelCache->write($parcelLabelNumber, $pdfContent);
			}
			
			// Return successful response
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'parcelLabelNumber' => $parcelLabelNumber,
				],
			];
		}
		
		/**
		 * Parses a SOAP getTrackingData response into a normalised array.
		 * @param string $body
		 * @param int $statusCode
		 * @return GatewayResponse
		 */
		private function parseTrackingResponse(string $body, int $statusCode): array {
			// Delegate HTTP-level errors to the error parser
			if ($statusCode >= 400) {
				return $this->parseErrorResponse($body, $statusCode);
			}
			
			// Parse the response XML
			$xml = $this->parseXml($body);
			
			if ($xml === null) {
				return ['request' => ['result' => 0, 'errorId' => 'parse_error', 'errorMessage' => 'Failed to parse DPD tracking response']];
			}
			
			// Check for a SOAP fault
			if ($fault = $this->soapFaultResponse($xml)) {
				return $fault;
			}
			
			// Convert the XML to an array for easier consumption in the driver
			$trackingResult = $xml->xpath('//*[local-name()="trackingresult"]')[0] ?? null;
			
			if ($trackingResult === null) {
				return ['request' => ['result' => 0, 'errorId' => 'not_found', 'errorMessage' => 'DPD returned no tracking result']];
			}
			
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'trackingresult' => $this->xmlToArray($trackingResult)
				],
			];
		}
		
		/**
		 * Parses a SOAP findParcelShops response into a normalised array.
		 * @param string $body
		 * @param int $statusCode
		 * @return GatewayResponse
		 */
		private function parseParcelShopResponse(string $body, int $statusCode): array {
			// Delegate HTTP-level errors to the error parser
			if ($statusCode >= 400) {
				return $this->parseErrorResponse($body, $statusCode);
			}
			
			// Parse the response XML
			$xml = $this->parseXml($body);
			
			if ($xml === null) {
				return ['request' => ['result' => 0, 'errorId' => 'parse_error', 'errorMessage' => 'Failed to parse DPD parcel shop response']];
			}
			
			// Check for a SOAP fault
			if ($fault = $this->soapFaultResponse($xml)) {
				return $fault;
			}
			
			// Extract all parcelShop elements and convert each to an array
			$shops  = $xml->xpath('//*[local-name()="parcelShop"]') ?: [];
			$result = [];
			
			foreach ($shops as $shop) {
				$result[] = $this->xmlToArray($shop);
			}
			
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => ['parcelShop' => $result],
			];
		}
		
		/**
		 * Parses a SOAP fault or HTTP error response.
		 * @param string $body
		 * @param int $statusCode
		 * @return GatewayResponse
		 */
		private function parseErrorResponse(string $body, int $statusCode): array {
			// Decode XML
			$xml = $this->parseXml($body);
			
			// Fetch error message from XML
			$message = "HTTP {$statusCode}";
			
			if ($xml !== null) {
				$fault = $xml->xpath('//faultstring');
				
				if (!empty($fault)) {
					$message = (string)$fault[0];
				}
			}
			
			// Return the error message
			return ['request' => ['result' => 0, 'errorId' => (string)$statusCode, 'errorMessage' => $message]];
		}
		
		/**
		 * Parses a raw SOAP XML string into a SimpleXMLElement.
		 * Returns null if parsing fails.
		 * @param string $body
		 * @return \SimpleXMLElement|null
		 */
		private function parseXml(string $body): ?\SimpleXMLElement {
			$previous = libxml_use_internal_errors(true);
			
			try {
				$xml = simplexml_load_string($body);
				libxml_clear_errors();
				return $xml ?: null;
			} finally {
				libxml_use_internal_errors($previous);
			}
		}
		
		/**
		 * Converts a SimpleXMLElement to a plain PHP array.
		 * Always returns an array; text-only elements produce ['_value' => '...']
		 * but this method is only called on known complex elements (trackingresult,
		 * parcelShop) so the _value fallback is a safety net, not a common path.
		 * Attributes are ignored.
		 * @param \SimpleXMLElement $element
		 * @return array<string, mixed>
		 */
		private function xmlToArray(\SimpleXMLElement $element): array {
			$result = [];
			
			foreach ($element->children() as $key => $child) {
				// Leaf nodes are converted to strings; complex nodes recurse
				$value = $child->count() > 0 ? $this->xmlToArray($child) : (string)$child;
				
				if (isset($result[$key])) {
					// Multiple children with same tag → promote to indexed array
					if (!is_array($result[$key]) || !isset($result[$key][0])) {
						$result[$key] = [$result[$key]];
					}
					
					$result[$key][] = $value;
				} else {
					$result[$key] = $value;
				}
			}
			
			// Text-only element with no children: capture the text content
			if ($result === []) {
				return ['_value' => (string)$element];
			}
			
			return $result;
		}
		
		/**
		 * Builds a SOAP 1.1 envelope with the standard DPD authentication header.
		 *
		 * The {{delisId}} and {{token}} placeholders are substituted by request()
		 * immediately before the HTTP call, after a valid token has been obtained.
		 *
		 * @param string $serviceNamespace The xmlns:ns1 namespace URI for the service body
		 * @param string $body             The inner XML content for <soapenv:Body>
		 * @return string Complete SOAP envelope
		 */
		private function buildEnvelope(string $serviceNamespace, string $body): string {
			return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="http://dpd.com/common/service/types/Authentication/2.0"
    xmlns:ns1="{$serviceNamespace}">
  <soapenv:Header>
    <ns:authentication>
      <delisId>{{delisId}}</delisId>
      <authToken>{{token}}</authToken>
      <messageLanguage>nl_NL</messageLanguage>
    </ns:authentication>
  </soapenv:Header>
  <soapenv:Body>
    {$body}
  </soapenv:Body>
</soapenv:Envelope>
XML;
		}

		/**
		 * Recursively serialises an associative array to XML child elements.
		 * Array values recurse; scalar values are escaped and emitted as text content.
		 * No outer wrapper element is added — the caller supplies that via a tag or
		 * by embedding the result directly in a heredoc.
		 * @param array<array-key, mixed> $data
		 * @return string
		 */
		private function buildXmlFragment(array $data): string {
			$xml = '';
			
			foreach ($data as $key => $value) {
				if (is_array($value)) {
					$xml .= "<{$key}>{$this->buildXmlFragment($value)}</{$key}>";
				} else {
					$xml .= "<{$key}>{$this->xmlEscape($this->normalizeString($value))}</{$key}>";
				}
			}
			
			return $xml;
		}
		
		/**
		 * Builds the XML block for a sender or recipient address.
		 * @param string $tag XML element name (e.g. 'sender' or 'recipient')
		 * @param array<string, mixed> $address
		 * @return string
		 */
		private function buildAddressXml(string $tag, array $address): string {
			return "<{$tag}>{$this->buildXmlFragment($address)}</{$tag}>";
		}
		
		/**
		 * Builds the XML child elements for the <parcels> section.
		 * @param array<array-key, mixed> $parcels
		 * @return string
		 */
		private function buildParcelsXml(array $parcels): string {
			return $this->buildXmlFragment($parcels);
		}
		
		/**
		 * Builds the XML child elements for the <productAndServiceData> section.
		 * @param array<array-key, mixed> $psd
		 * @return string
		 */
		private function buildProductAndServiceDataXml(array $psd): string {
			return $this->buildXmlFragment($psd);
		}
		
		/**
		 * Escapes a string for safe embedding in XML.
		 * @param string $value
		 * @return string
		 */
		private function xmlEscape(string $value): string {
			return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
		}
	}