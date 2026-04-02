<?php
	
	namespace Quellabs\Shipments\DPD;
	
	use Symfony\Component\HttpClient\HttpClient;
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
	 */
	class DPDGateway {
		
		/** Live base URL for all DPD Shipper Webservice endpoints */
		private const BASE_URL_LIVE = 'https://wsshipper.dpd.nl/soap/services';
		
		/** Stage base URL for all DPD Shipper Webservice endpoints */
		private const BASE_URL_TEST = 'https://shipperadmintest.dpd.nl/PublicAPI/soap/services';
		
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
			$isTest = (bool)($config['test_mode'] ?? false);
			
			$this->baseUrl = $isTest ? self::BASE_URL_TEST : self::BASE_URL_LIVE;
			$this->delisId = $config['delis_id'];
			$this->password = $config['password'];
			$this->labelCache = new LabelFileCache(
				$config['cache_path'] ?? 'storage/dpd/labels',
				(int)($config['label_cache_ttl_days'] ?? 30),
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
		 * @param array $payload Structured shipment data (generalShipmentData, parcels, productAndServiceData)
		 * @return array
		 */
		public function createShipment(array $payload): array {
			$token = $this->getValidToken();
			
			if ($token === null) {
				return ['request' => ['result' => 0, 'errorId' => 'auth_failed', 'errorMessage' => 'DPD authentication failed']];
			}
			
			$general = $payload['generalShipmentData'];
			$parcels = $payload['parcels'];
			$psd = $payload['productAndServiceData'];
			
			$senderXml = $this->buildAddressXml($general['sender']);
			$recipientXml = $this->buildAddressXml($general['recipient']);
			$parcelsXml = $this->buildParcelsXml($parcels);
			$psdXml = $this->buildProductAndServiceDataXml($psd);
			
			$xml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="http://dpd.com/common/service/types/Authentication/2.0"
    xmlns:ns1="http://dpd.com/common/service/types/ShipmentService/3.5">
  <soapenv:Header>
    <ns:authentication>
      <delisId>{$this->delisId}</delisId>
      <authToken>{$token}</authToken>
      <messageLanguage>nl_NL</messageLanguage>
    </ns:authentication>
  </soapenv:Header>
  <soapenv:Body>
    <ns1:storeOrders>
      <printOptions>
        <printerLanguage>PDF</printerLanguage>
        <paperFormat>A6</paperFormat>
      </printOptions>
      <order>
        <generalShipmentData>
          <sendingDepot>{$this->xmlEscape($general['sendingDepot'])}</sendingDepot>
          <product>{$this->xmlEscape($general['product'])}</product>
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
  </soapenv:Body>
</soapenv:Envelope>
XML;
			
			try {
				$response = $this->client->request('POST', $this->baseUrl . '/ShipmentService', [
					'headers' => [
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '""',
					],
					'body'    => $xml,
				]);
				
				return $this->parseShipmentResponse($response->getContent(false), $response->getStatusCode());
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Fetches tracking data for a parcel via the ParcelLifeCycle Service.
		 * @param string $parcelLabelNumber 14-digit DPD barcode
		 * @return array
		 */
		public function getTrackingData(string $parcelLabelNumber): array {
			$token = $this->getValidToken();
			
			if ($token === null) {
				return ['request' => ['result' => 0, 'errorId' => 'auth_failed', 'errorMessage' => 'DPD authentication failed']];
			}
			
			$xml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="http://dpd.com/common/service/types/Authentication/2.0"
    xmlns:ns1="http://dpd.com/common/service/types/ParcelLifeCycleService/2.0">
  <soapenv:Header>
    <ns:authentication>
      <delisId>{$this->delisId}</delisId>
      <authToken>{$token}</authToken>
      <messageLanguage>nl_NL</messageLanguage>
    </ns:authentication>
  </soapenv:Header>
  <soapenv:Body>
    <ns1:getTrackingData>
      <parcelLabelNumber>{$this->xmlEscape($parcelLabelNumber)}</parcelLabelNumber>
    </ns1:getTrackingData>
  </soapenv:Body>
</soapenv:Envelope>
XML;
			
			try {
				$response = $this->client->request('POST', $this->baseUrl . '/ParcelLifeCycleService', [
					'headers' => [
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '""',
					],
					'body'    => $xml,
				]);
				
				return $this->parseTrackingResponse($response->getContent(false), $response->getStatusCode());
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Finds nearby DPD parcel shops by postal code, country and city.
		 * @param string $country ISO 3166-1 alpha-2
		 * @param string $postalCode
		 * @param string $city
		 * @param int $limit Max results (server-side cap: 100)
		 * @return array
		 */
		public function findParcelShops(string $country, string $postalCode, string $city, int $limit = 10): array {
			$token = $this->getValidToken();
			
			if ($token === null) {
				return ['request' => ['result' => 0, 'errorId' => 'auth_failed', 'errorMessage' => 'DPD authentication failed']];
			}
			
			$xml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="http://dpd.com/common/service/types/Authentication/2.0"
    xmlns:ns1="http://dpd.com/common/service/types/ParcelShopFinderService/5.0">
  <soapenv:Header>
    <ns:authentication>
      <delisId>{$this->delisId}</delisId>
      <authToken>{$token}</authToken>
      <messageLanguage>nl_NL</messageLanguage>
    </ns:authentication>
  </soapenv:Header>
  <soapenv:Body>
    <ns1:findParcelShops>
      <country>{$this->xmlEscape($country)}</country>
      <zipCode>{$this->xmlEscape($postalCode)}</zipCode>
      <city>{$this->xmlEscape($city)}</city>
      <limit>{$limit}</limit>
    </ns1:findParcelShops>
  </soapenv:Body>
</soapenv:Envelope>
XML;
			
			try {
				$response = $this->client->request('POST', $this->baseUrl . '/ParcelShopFinderService', [
					'headers' => [
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '""',
					],
					'body'    => $xml,
				]);
				
				return $this->parseParcelShopResponse($response->getContent(false), $response->getStatusCode());
			} catch (\Throwable $e) {
				return ['request' => ['result' => 0, 'errorId' => $e->getCode(), 'errorMessage' => $e->getMessage()]];
			}
		}
		
		/**
		 * Returns the base64 label content for a given parcel label number.
		 *
		 * Reads from the file cache written by createShipment(). Works across
		 * requests and processes as long as the cache file has not expired.
		 * Purges stale files opportunistically on each call.
		 *
		 * @param string $parcelLabelNumber
		 * @return array
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
		 * @throws \DateInvalidOperationException
		 */
		private function getValidToken(): ?string {
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$margin = new \DateInterval('PT5M'); // 5-minute safety margin
			
			if (
				$this->authToken !== null &&
				$this->authTokenExpires !== null &&
				$this->authTokenExpires->sub($margin) > $now
			) {
				return $this->authToken;
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
				$response = $this->client->request('POST', $this->baseUrl . '/LoginService', [
					'headers' => [
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '""',
					],
					'body'    => $xml,
				]);
				
				$body = $response->getContent(false);
				$xml = $this->parseXml($body);
				
				if ($xml === null) {
					return null;
				}
				
				$xml->registerXPathNamespace('ns', 'http://dpd.com/common/service/types/LoginService/2.1');
				$result = $xml->xpath('//ns:return')[0] ?? null;
				
				if ($result === null) {
					return null;
				}
				
				$token = (string)($result->authToken ?? '');
				$expires = (string)($result->authTokenExpires ?? '');
				
				if (empty($token)) {
					return null;
				}
				
				$this->authToken = $token;
				
				// Parse expiry timestamp — DPD returns UTC datetime e.g. '2020-05-08T13:02:56.06'
				try {
					$this->authTokenExpires = new \DateTimeImmutable($expires, new \DateTimeZone('UTC'));
				} catch (\Throwable) {
					// If we can't parse the expiry, assume 23 hours to be safe
					$this->authTokenExpires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval('PT23H'));
				}
				
				return $this->authToken;
			} catch (\Throwable) {
				return null;
			}
		}
		
		/**
		 * Parses a SOAP storeOrders response and extracts the parcel label number and label content.
		 * @param string $body Raw SOAP response body
		 * @param int $statusCode HTTP status code
		 * @return array
		 */
		private function parseShipmentResponse(string $body, int $statusCode): array {
			if ($statusCode >= 400) {
				return $this->parseErrorResponse($body, $statusCode);
			}
			
			$xml = $this->parseXml($body);
			
			if ($xml === null) {
				return ['request' => ['result' => 0, 'errorId' => 'parse_error', 'errorMessage' => 'Failed to parse DPD shipment response']];
			}
			
			// Check for fault
			$fault = $xml->xpath('//faultstring');
			
			if (!empty($fault)) {
				return ['request' => ['result' => 0, 'errorId' => 'soap_fault', 'errorMessage' => (string)$fault[0]]];
			}
			
			// Look for error in response
			$xml->registerXPathNamespace('ns1', 'http://dpd.com/common/service/types/ShipmentService/3.5');
			$response = $xml->xpath('//ns1:storeOrdersResponse/return')[0] ?? null;
			
			if ($response === null) {
				// Try without namespace
				$response = $xml->xpath('//*[local-name()="return"]')[0] ?? null;
			}
			
			if ($response === null) {
				return ['request' => ['result' => 0, 'errorId' => 'empty_response', 'errorMessage' => 'DPD returned an empty shipment response']];
			}
			
			// Check for fault in the response body
			$faultCode = (string)($response->shipmentFaultCode ?? '');
			$faultMessage = (string)($response->shipmentFaultDescription ?? '');
			
			if (!empty($faultCode)) {
				return ['request' => ['result' => 0, 'errorId' => $faultCode, 'errorMessage' => $faultMessage ?: "DPD fault: {$faultCode}"]];
			}
			
			$parcelLabelNumber = (string)($response->parcelLabelNumber ?? '');
			
			// Label PDF is in the parcels/label/content node (field: parcellabelsPDF)
			$labelNode = $xml->xpath('//*[local-name()="parcels"]/*[local-name()="label"]/*[local-name()="content"]');
			$pdfContent = !empty($labelNode) ? (string)$labelNode[0] : null;
			
			// Persist label to file cache so getLabelUrl() can retrieve it across requests
			if ($parcelLabelNumber !== '' && $pdfContent !== null) {
				$this->labelCache->write($parcelLabelNumber, $pdfContent);
			}
			
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
		 * @return array
		 */
		private function parseTrackingResponse(string $body, int $statusCode): array {
			if ($statusCode >= 400) {
				return $this->parseErrorResponse($body, $statusCode);
			}
			
			$xml = $this->parseXml($body);
			
			if ($xml === null) {
				return ['request' => ['result' => 0, 'errorId' => 'parse_error', 'errorMessage' => 'Failed to parse DPD tracking response']];
			}
			
			$fault = $xml->xpath('//faultstring');
			
			if (!empty($fault)) {
				return ['request' => ['result' => 0, 'errorId' => 'soap_fault', 'errorMessage' => (string)$fault[0]]];
			}
			
			// Convert the XML to an array for easier consumption in the driver
			$trackingResult = $xml->xpath('//*[local-name()="trackingresult"]')[0] ?? null;
			
			if ($trackingResult === null) {
				return ['request' => ['result' => 0, 'errorId' => 'not_found', 'errorMessage' => 'DPD returned no tracking result']];
			}
			
			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => ['trackingresult' => $this->xmlToArray($trackingResult)],
			];
		}
		
		/**
		 * Parses a SOAP findParcelShops response into a normalised array.
		 * @param string $body
		 * @param int $statusCode
		 * @return array
		 */
		private function parseParcelShopResponse(string $body, int $statusCode): array {
			if ($statusCode >= 400) {
				return $this->parseErrorResponse($body, $statusCode);
			}
			
			$xml = $this->parseXml($body);
			
			if ($xml === null) {
				return ['request' => ['result' => 0, 'errorId' => 'parse_error', 'errorMessage' => 'Failed to parse DPD parcel shop response']];
			}
			
			$fault = $xml->xpath('//faultstring');
			
			if (!empty($fault)) {
				return ['request' => ['result' => 0, 'errorId' => 'soap_fault', 'errorMessage' => (string)$fault[0]]];
			}
			
			$shops = $xml->xpath('//*[local-name()="parcelShop"]');
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
		 * @return array
		 */
		private function parseErrorResponse(string $body, int $statusCode): array {
			$xml = $this->parseXml($body);
			$message = "HTTP {$statusCode}";
			
			if ($xml !== null) {
				$fault = $xml->xpath('//faultstring');
				
				if (!empty($fault)) {
					$message = (string)$fault[0];
				}
			}
			
			return ['request' => ['result' => 0, 'errorId' => $statusCode, 'errorMessage' => $message]];
		}
		
		/**
		 * Parses a raw SOAP XML string into a SimpleXMLElement.
		 * Returns null if parsing fails.
		 * @param string $body
		 * @return \SimpleXMLElement|null
		 */
		private function parseXml(string $body): ?\SimpleXMLElement {
			try {
				libxml_use_internal_errors(true);
				$xml = simplexml_load_string($body);
				libxml_clear_errors();
				return $xml ?: null;
			} catch (\Throwable) {
				return null;
			}
		}
		
		/**
		 * Recursively converts a SimpleXMLElement to a plain PHP array.
		 * Attributes are ignored; text-only nodes are returned as strings.
		 * @param \SimpleXMLElement $element
		 * @return array|string
		 */
		private function xmlToArray(\SimpleXMLElement $element): array|string {
			$result = [];
			
			foreach ($element->children() as $key => $child) {
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
			
			return $result ?: (string)$element;
		}
		
		/**
		 * Builds the XML block for a sender or recipient address.
		 * @param array $address
		 * @return string
		 */
		private function buildAddressXml(array $address): string {
			// Determine if this is a sender or recipient based on presence of specific keys
			// Actually we pass the tag name from the caller — simplify by inferring from the array
			// We use a helper that wraps all provided keys as XML
			$inner = '';
			
			foreach ($address as $key => $value) {
				if ($key === '_tag') {
					continue;
				}
				
				$inner .= "<{$key}>{$this->xmlEscape((string)$value)}</{$key}>";
			}
			
			return "<recipient>{$inner}</recipient>";
		}
		
		/**
		 * Builds the XML block for the <parcels> section.
		 * @param array $parcels
		 * @return string
		 */
		private function buildParcelsXml(array $parcels): string {
			$xml = '';
			
			foreach ($parcels as $key => $value) {
				$xml .= "<{$key}>{$this->xmlEscape((string)$value)}</{$key}>";
			}
			
			return $xml;
		}
		
		/**
		 * Builds the XML block for the <productAndServiceData> section.
		 * Handles nested structures (e.g. parcelShopDelivery).
		 * @param array $psd
		 * @return string
		 */
		private function buildProductAndServiceDataXml(array $psd): string {
			$xml = '';
			
			foreach ($psd as $key => $value) {
				if (is_array($value)) {
					$inner = '';
					
					foreach ($value as $k => $v) {
						if (is_array($v)) {
							$nested = '';
							
							foreach ($v as $nk => $nv) {
								$nested .= "<{$nk}>{$this->xmlEscape((string)$nv)}</{$nk}>";
							}
							
							$inner .= "<{$k}>{$nested}</{$k}>";
						} else {
							$inner .= "<{$k}>{$this->xmlEscape((string)$v)}</{$k}>";
						}
					}
					
					$xml .= "<{$key}>{$inner}</{$key}>";
				} else {
					$xml .= "<{$key}>{$this->xmlEscape((string)$value)}</{$key}>";
				}
			}
			
			return $xml;
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