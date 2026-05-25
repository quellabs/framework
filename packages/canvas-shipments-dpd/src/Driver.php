<?php
	
	namespace Quellabs\Shipments\DPD;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\DeliveryOption;
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentCancellationException;
	use Quellabs\Shipments\Contracts\ShipmentCreationException;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\Shipments\Contracts\ShipmentLabelException;
	use Quellabs\Shipments\Contracts\ShipmentProviderInterface;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	
	class Driver implements ShipmentProviderInterface {
		
		use GatewayHelpers;
		
		/**
		 * Driver name — stored in ShipmentResult::$provider and ShipmentState::$provider.
		 * Used by ShipmentRouter::exchange() to re-resolve this driver later.
		 */
		const string DRIVER_NAME = 'dpd';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var DPDGateway|null
		 */
		private ?DPDGateway $gateway = null;
		
		/**
		 * Maps our internal module names to DPD product codes and service flags.
		 *
		 * DPD product values used in <generalShipmentData><product>:
		 *   B2B   = DPD Business (default, service codes 101/136)
		 *   B2C   = DPD Home (service codes 327/328), also used for Shop and Saturday
		 *   PL    = DPD ParcelLetter (service code 154)
		 *   E830  = DPD Business Express 08:30 (service code 350)
		 *   E10   = DPD Business Express 10:00 (service code 179)
		 *   E12   = DPD Business Express 12:00 (service code 225)
		 *   PM2   = DPD Guarantee (service code 155)
		 *
		 * Additional flags drive optional XML blocks in the shipment request:
		 *   'saturday'  => true  adds <saturdayDelivery>true</saturdayDelivery>
		 *   'ageCheck'  => true  adds <ageCheck>true</ageCheck>
		 *   'shop'      => true  requires ShipmentRequest::$servicePointId (DPD Shop)
		 *   'return'    => true  adds <returns>true</returns> to <parcels>
		 *
		 * @see https://integrations.dpd.nl/dpd-shipper/dpd-shipper-webservices/shipment-service-api/
		 */
		private const array MODULE_PRODUCT_MAP = [
			// Standard home and business delivery
			'dpd_b2b'           => ['product' => 'B2B'],
			'dpd_b2c'           => ['product' => 'B2C'],
			'dpd_parcel_letter' => ['product' => 'PL'],
			
			// Saturday delivery
			'dpd_b2b_saturday'  => ['product' => 'B2B', 'saturday' => true],
			'dpd_b2c_saturday'  => ['product' => 'B2C', 'saturday' => true],
			
			// Age check
			'dpd_b2b_age_check' => ['product' => 'B2B', 'ageCheck' => true],
			'dpd_b2c_age_check' => ['product' => 'B2C', 'ageCheck' => true],
			
			// DPD Shop (pickup point delivery — requires servicePointId)
			'dpd_shop'          => ['product' => 'B2C', 'shop' => true],
			'dpd_shop_return'   => ['product' => 'B2C', 'shop' => true, 'return' => true],
			
			// Express
			'dpd_express_830'   => ['product' => 'E830'],
			'dpd_express_10'    => ['product' => 'E10'],
			'dpd_express_12'    => ['product' => 'E12'],
			'dpd_guarantee'     => ['product' => 'PM2'],
		];
		
		/**
		 * Maps DPD ParcelLifeCycle status strings to our normalised ShipmentStatus values.
		 *
		 * Status strings are returned in <statusInfo><status> of the tracking response.
		 * DPD does not document a complete closed list; these are the values observed in
		 * the API documentation examples and known integrations.
		 *
		 * @see https://integrations.dpd.nl/dpd-shipper/dpd-shipper-webservices/parcellifecycle-service/
		 */
		private const array STATUS_MAP = [
			'SHIPMENT'        => ShipmentStatus::Created,
			'PickupRequest'   => ShipmentStatus::Created,
			'BetweenDepots'   => ShipmentStatus::InTransit,
			'Depot'           => ShipmentStatus::OutForDelivery,
			'Courier'         => ShipmentStatus::OutForDelivery,
			'Delivered'       => ShipmentStatus::Delivered,
			'ParcelShop'      => ShipmentStatus::AwaitingPickup,
			'DeliveryFailure' => ShipmentStatus::DeliveryFailed,
			'ReturnToSender'  => ShipmentStatus::ReturnedToSender,
			'Lost'            => ShipmentStatus::Lost,
			'Cancelled'       => ShipmentStatus::Cancelled,
		];
		
		/**
		 * Returns discovery metadata for this provider.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_PRODUCT_MAP),
			];
		}
		
		/**
		 * Returns the active configuration for this driver instance.
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this driver instance.
		 * Called by the discovery system after instantiation, before any other methods.
		 * @param array<string, mixed> $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this driver.
		 * @return array{
		 *     delis_id: string,
		 *     password: string,
		 *     sending_depot: string,
		 *     test_mode: bool,
		 *     test_delis_id: string,
		 *     test_password: string,
		 *     test_sending_depot: string,
		 *     cache_path: string,
		 *     label_cache_ttl_days: int,
		 *     sender_address: array<string, mixed>
		 * }
		 */
		public function getDefaults(): array {
			return [
				'delis_id'             => '',
				'password'             => '',
				'sending_depot'        => '',
				'test_mode'            => false,
				'test_delis_id'        => '',
				'test_password'        => '',
				'test_sending_depot'   => '',
				'cache_path'           => 'storage/dpd/labels',
				'label_cache_ttl_days' => 30,
				'sender_address'       => [],
			];
		}
		
		/**
		 * Creates a DPD shipment and returns a structured result.
		 *
		 * Sends a SOAP storeOrders request. DPD returns the parcel label number (14-digit
		 * barcode) and the label PDF as a base64-encoded string in the same response.
		 * The label PDF from the response is written to the file cache so that
		 * getLabelUrl() can retrieve it in any subsequent request or process.
		 *
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 * @throws ShipmentCreationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			$productInfo = self::MODULE_PRODUCT_MAP[$request->shippingModule] ?? null;
			
			if ($productInfo === null) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'unknown_module',
					"Unknown shipping module '{$request->shippingModule}'"
				);
			}
			
			$config              = $this->getConfig();
			$address             = $request->deliveryAddress;
			$isTest              = is_bool($config['test_mode'] ?? null) && $config['test_mode'];
			$testSendingDepot    = is_string($config['test_sending_depot'] ?? null) ? $config['test_sending_depot'] : '';
			$sendingDepotDefault = is_string($config['sending_depot'] ?? null) ? $config['sending_depot'] : '';
			
			if ($isTest) {
				$sendingDepot = $testSendingDepot ?: $sendingDepotDefault;
			} else {
				$sendingDepot = $sendingDepotDefault;
			}
			
			// Build the productAndServiceData block
			$productAndServiceData = ['orderType' => 'consignment'];
			
			if (!empty($productInfo['saturday'])) {
				$productAndServiceData['saturdayDelivery'] = 'true';
			}
			
			if (!empty($productInfo['ageCheck'])) {
				$productAndServiceData['ageCheck'] = 'true';
			}
			
			// DPD Shop requires a parcelShopId; fail early if missing
			if (!empty($productInfo['shop'])) {
				if ($request->servicePointId === null) {
					throw new ShipmentCreationException(
						self::DRIVER_NAME,
						'missing_service_point',
						"Module '{$request->shippingModule}' requires a servicePointId (DPD Shop)"
					);
				}
				
				$productAndServiceData['parcelShopDelivery'] = [
					'parcelShopId'           => $request->servicePointId,
					'parcelShopNotification' => array_filter([
						'channel'  => 1, // 1 = email
						'value'    => $address->email,
						'language' => strtoupper(substr($address->country, 0, 2)),
					], fn($v) => $v !== null && $v !== ''),
				];
			}
			
			// Build the parcels block
			$parcels = array_filter([
				'customerReferenceNumber1' => $request->reference,
				'weight'                   => $request->weightGrams,
				'returns'                  => !empty($productInfo['return']) ? 'true' : null,
			], fn($v) => $v !== null);
			
			// Build sender block from config
			$sender = $this->buildSenderBlock($config);
			
			// Build recipient block
			$recipient = array_filter([
				'name1'   => $address->name,
				'name2'   => $address->company,
				'street'  => $address->street,
				'houseNo' => $address->houseNumber . (!empty($address->houseNumberSuffix) ? ' ' . $address->houseNumberSuffix : ''),
				'country' => $address->country,
				'zipCode' => $address->postalCode,
				'city'    => $address->city,
				'email'   => $address->email,
				'phone'   => $address->phone,
			], fn($v) => $v !== null && $v !== '');
			
			$payload = [
				'generalShipmentData'   => [
					'sendingDepot' => $sendingDepot,
					'product'      => $productInfo['product'],
					'sender'       => $sender,
					'recipient'    => $recipient,
				],
				'parcels'               => $parcels,
				'productAndServiceData' => $productAndServiceData,
			];
			
			// Merge any extra data into the payload
			if (!empty($request->extraData)) {
				$payload = array_merge_recursive($payload, $request->extraData);
			}
			
			// Call the API to create a shipment
			$result = $this->getGateway()->createShipment($payload);
			
			// If that failed, throw exception
			if ($result['request']['result'] === 0) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch the response
			$response = $result['response'] ?? [];
			
			// DPD returns the 14-digit parcel label number as the stable identifier
			if (empty($response['parcelLabelNumber'])) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_label_number',
					'DPD did not return a parcel label number in the creation response'
				);
			}
			
			$parcelLabelNumber = $this->normalizeString($response['parcelLabelNumber'] ?? null);
			
			// Return the shipment result
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: $parcelLabelNumber,
				reference: $request->reference,
				trackingCode: $parcelLabelNumber,
				trackingUrl: $this->buildTrackingUrl($parcelLabelNumber),
				carrierName: 'DPD',
				rawResponse: $response,
			);
		}
		
		/**
		 * DPD does not expose a cancellation endpoint in their Shipper Webservice API.
		 * Shipments must be cancelled via the DPD customer service before depot handover.
		 * @param CancelRequest $request
		 * @return CancelResult
		 * @throws ShipmentCancellationException always
		 */
		public function cancel(CancelRequest $request): CancelResult {
			throw new ShipmentCancellationException(
				self::DRIVER_NAME,
				'not_supported',
				'DPD does not support programmatic parcel cancellation via the Shipper Webservice API. ' .
				'Contact DPD customer service before 22:00 on the day the order was placed.'
			);
		}
		
		/**
		 * Fetches the current state of a DPD parcel by parcel label number.
		 *
		 * The ParcelLifeCycle service returns an array of statusInfo entries ordered
		 * chronologically. We derive the current status from the entry where
		 * <isCurrentStatus> is true, falling back to the last entry.
		 *
		 * @param string $parcelId The DPD parcel label number from ShipmentResult::$parcelId
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function exchange(string $parcelId): ShipmentState {
			// Call the API to fetch tracking data for the parcel
			$result = $this->getGateway()->getTrackingData($parcelId);
			
			// Throw if that API call failed
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch the response
			$response = $result['response'] ?? [];
			
			// Validate the response
			if (!is_array($response) || !isset($response['trackingresult']) || !is_array($response['trackingresult'])) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					'not_found',
					"DPD returned no tracking data for parcel label number {$parcelId}"
				);
			}
			
			return $this->buildStateFromTracking($parcelId, $response['trackingresult']);
		}
		
		/**
		 * DPD does not provide a consumer-facing delivery timeslot options API.
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return DeliveryOption[]
		 */
		public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			return [];
		}
		
		/**
		 * Returns nearby DPD parcel shops for the given address.
		 *
		 * Uses the ParcelShopFinder service, searching by postal code, country, and city.
		 * Returns an empty array when no address is provided, since DPD requires a
		 * search origin. Requires a valid auth token — the gateway handles this.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return PickupOption[]
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			if ($address === null) {
				return [];
			}
			
			$result = $this->getGateway()->findParcelShops(
				$address->country,
				$address->postalCode,
				$address->city,
			);
			
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			$shops = $result['response']['parcelShop'] ?? [];
			
			if (!is_array($shops)) {
				return [];
			}
			
			// findParcelShops returns a single shop as an object, not an array of objects
			if (isset($shops['parcelShopId'])) {
				$shops = [$shops];
			}
			
			$options = [];
			
			foreach ($shops as $shop) {
				// Skip malformed entries
				if (!is_array($shop)) {
					continue;
				}
				
				// Extract data
				$locationCode   = $this->normalizeString($shop['parcelShopId'] ?? null);
				$name           = is_string($shop['company'] ?? null) ? $shop['company'] : '';
				$street         = is_string($shop['street'] ?? null) ? $shop['street'] : '';
				$houseNumber    = $this->normalizeString($shop['houseNo'] ?? null);
				$postalCode     = is_string($shop['zipCode'] ?? null) ? $shop['zipCode'] : '';
				$city           = is_string($shop['city'] ?? null) ? $shop['city'] : '';
				$country        = is_string($shop['country'] ?? null) ? $shop['country'] : $address->country;
				$latitude       = isset($shop['latitude']) && is_numeric($shop['latitude']) ? (float)$shop['latitude'] : null;
				$longitude      = isset($shop['longitude']) && is_numeric($shop['longitude']) ? (float)$shop['longitude'] : null;
				$distanceMetres = isset($shop['distance']) && is_numeric($shop['distance']) ? (int)round((float)$shop['distance'] * 1000) : null;
				
				// Add pickup option to list
				$options[] = new PickupOption(
					locationCode: $locationCode,
					name: $name,
					street: $street,
					houseNumber: $houseNumber,
					postalCode: $postalCode,
					city: $city,
					country: $country,
					carrierName: 'DPD',
					latitude: $latitude,
					longitude: $longitude,
					distanceMetres: $distanceMetres,
					metadata: array_filter([
						'openingHours' => $shop['openingHours'] ?? null,
						'phone'        => $shop['phone'] ?? null,
						'email'        => $shop['email'] ?? null,
					], fn($v) => $v !== null && $v !== '')
				);
			}
			
			return $options;
		}
		
		/**
		 * Returns the label for a given parcel label number as a base64 data URI.
		 *
		 * DPD does not provide a label re-fetch endpoint. The label PDF is written
		 * to a file cache at creation time and read back here. Works across requests
		 * and processes within the configured TTL (label_cache_ttl_days).
		 *
		 * @param string $parcelId The DPD parcel label number
		 * @return string data:application/pdf;base64,...
		 * @throws ShipmentLabelException
		 */
		public function getLabelUrl(string $parcelId): string {
			// Call api to get label
			$result = $this->getGateway()->getLabel($parcelId);
			
			// If that failed, bail
			if ($result['request']['result'] === 0) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch response
			$response     = $result['response'] ?? [];
			$labelContent = $response['labelContent'] ?? null;
			
			if (!is_string($labelContent) || $labelContent === '') {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					'missing_label',
					"DPD returned no label content for parcel label number {$parcelId}"
				);
			}
			
			// Return label content
			return 'data:application/pdf;base64,' . $labelContent;
		}
		
		/**
		 * Builds a ShipmentState from a DPD ParcelLifeCycle tracking result.
		 * Used by exchange(). The current status entry is the one where isCurrentStatus = true;
		 * we fall back to the last statusInfo entry if none is explicitly marked current.
		 * @param string $parcelId The DPD parcel label number
		 * @param array<array-key, mixed> $trackingResult Decoded tracking response
		 * @return ShipmentState
		 */
		public function buildStateFromTracking(string $parcelId, array $trackingResult): ShipmentState {
			$statusInfos = $trackingResult['statusInfo'] ?? [];
			
			if (!is_array($statusInfos)) {
				$statusInfos = [];
			}
			
			// Normalise single-entry response (XML-to-array may not wrap in array)
			if (isset($statusInfos['status'])) {
				$statusInfos = [$statusInfos];
			}
			
			// Find the entry marked as current; fall back to last entry
			$current = null;
			
			foreach ($statusInfos as $info) {
				if (!is_array($info)) {
					continue;
				}
				
				if (($info['isCurrentStatus'] ?? 'false') === 'true') {
					$current = $info;
				}
			}
			
			if ($current === null) {
				$last    = end($statusInfos);
				$current = is_array($last) ? $last : [];
			}
			
			$statusCode = is_string($current['status'] ?? null) ? $current['status'] : 'unknown';
			$status     = self::STATUS_MAP[$statusCode] ?? ShipmentStatus::Unknown;
			
			// Extract human-readable label text from the nested label/content structure
			$labelNode = $current['label'] ?? null;
			
			if (is_array($labelNode) && is_string($labelNode['content'] ?? null)) {
				$statusMessage = $labelNode['content'];
			} else {
				$statusMessage = null;
			}
			
			// Location and timestamp from the current status entry
			$locationNode = $current['location'] ?? null;
			
			if (is_array($locationNode) && is_string($locationNode['content'] ?? null)) {
				$location = $locationNode['content'];
			} else {
				$location = null;
			}
			
			$dateNode = $current['date'] ?? null;
			
			if (is_array($dateNode) && is_string($dateNode['content'] ?? null)) {
				$timestamp = $dateNode['content'];
			} else {
				$timestamp = null;
			}
			
			return new ShipmentState(
				provider: self::DRIVER_NAME,
				parcelId: $parcelId,
				reference: '',
				state: $status,
				trackingCode: $parcelId,
				trackingUrl: $this->buildTrackingUrl($parcelId),
				statusMessage: $statusMessage,
				internalState: $statusCode,
				metadata: array_filter([
					'location'  => $location,
					'timestamp' => $timestamp,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Lazily instantiates and returns the DPD gateway.
		 * @return DPDGateway
		 */
		private function getGateway(): DPDGateway {
			return $this->gateway ??= new DPDGateway($this);
		}
		
		/**
		 * Builds the sender block for the shipment request from the configured sender_address.
		 * @param array<string, mixed> $config
		 * @return array<string, mixed>
		 * @throws ShipmentCreationException when sender_address is not configured
		 */
		private function buildSenderBlock(array $config): array {
			$sender = $config['sender_address'];
			
			if (empty($sender)) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_sender_address',
					'DPD requires a sender address. Configure sender_address in the dpd.php config file.'
				);
			}
			
			if (!is_array($sender)) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'invalid_sender_address',
					'DPD sender_address must be an array.'
				);
			}

			/** @var array<string, mixed> $sender */
			// All values come from array<string, mixed>; extract as strings with fallbacks
			$company    = is_string($sender['company'] ?? null) ? $sender['company'] : null;
			$name       = is_string($sender['name'] ?? null) ? $sender['name'] : null;
			$street     = $this->normalizeString($sender['street'] ?? null);
			$number     = $this->normalizeString($sender['number'] ?? null);
			$cc         = $this->normalizeString($sender['cc'] ?? null, 'NL');
			$postalCode = $this->normalizeString($sender['postal_code'] ?? null);
			$city       = $this->normalizeString($sender['city'] ?? null);
			$phone      = is_string($sender['phone'] ?? null) ? $sender['phone'] : null;
			$email      = is_string($sender['email'] ?? null) ? $sender['email'] : null;
			
			return array_filter([
				'name1'   => $company ?? $name ?? '',
				'name2'   => $name,
				'street'  => $street,
				'houseNo' => $number,
				'country' => $cc,
				'zipCode' => $postalCode,
				'city'    => $city,
				'phone'   => $phone,
				'email'   => $email,
			], fn($v) => $v !== null && $v !== '');
		}
		
		/**
		 * Constructs the public DPD track-and-trace URL for a parcel label number.
		 * @param string $parcelLabelNumber 14-digit DPD barcode
		 * @return string
		 */
		private function buildTrackingUrl(string $parcelLabelNumber): string {
			return 'https://tracking.dpd.de/status/nl_NL/parcel/' . rawurlencode($parcelLabelNumber);
		}
	}