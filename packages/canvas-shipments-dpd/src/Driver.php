<?php
	
	namespace Quellabs\Shipments\DPD;
	
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
		
		/**
		 * Driver name — stored in ShipmentResult::$provider and ShipmentState::$provider.
		 * Used by ShipmentRouter::exchange() to re-resolve this driver later.
		 */
		const DRIVER_NAME = 'dpd';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array
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
		private const MODULE_PRODUCT_MAP = [
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
		private const STATUS_MAP = [
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
		 * @return array
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this driver instance.
		 * Called by the discovery system after instantiation, before any other methods.
		 * @param array $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this driver.
		 * @return array
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
			
			$config = $this->getConfig();
			$address = $request->deliveryAddress;
			$isTest = (bool)($config['test_mode'] ?? false);
			
			if ($isTest) {
				$sendingDepot = $config['test_sending_depot'] ?: $config['sending_depot'];
			} else {
				$sendingDepot = $config['sending_depot'];
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
			
			$result = $this->getGateway()->createShipment($payload);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// DPD returns the 14-digit parcel label number as the stable identifier
			$parcelLabelNumber = $result['response']['parcelLabelNumber'] ?? null;
			
			if (empty($parcelLabelNumber)) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_label_number',
					'DPD did not return a parcel label number in the creation response'
				);
			}
			
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: (string)$parcelLabelNumber,
				reference: $request->reference,
				trackingCode: (string)$parcelLabelNumber,
				trackingUrl: $this->buildTrackingUrl($parcelLabelNumber),
				carrierName: 'DPD',
				rawResponse: $result['response'],
			);
		}
		
		/**
		 * DPD does not expose a cancellation endpoint in their Shipper Webservice API.
		 * Shipments must be cancelled via the DPD customer service before depot handover.
		 *
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
			$result = $this->getGateway()->getTrackingData($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$trackingResult = $result['response']['trackingresult'] ?? null;
			
			if ($trackingResult === null) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					'not_found',
					"DPD returned no tracking data for parcel label number {$parcelId}"
				);
			}
			
			return $this->buildStateFromTracking($parcelId, $trackingResult);
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
			
			// findParcelShops returns a single shop as an object, not an array of objects
			if (isset($shops['parcelShopId'])) {
				$shops = [$shops];
			}
			
			$options = [];
			
			foreach ($shops as $shop) {
				$options[] = new PickupOption(
					locationCode: (string)($shop['parcelShopId'] ?? ''),
					name: $shop['company'] ?? '',
					street: $shop['street'] ?? '',
					houseNumber: (string)($shop['houseNo'] ?? ''),
					postalCode: $shop['zipCode'] ?? '',
					city: $shop['city'] ?? '',
					country: $shop['country'] ?? $address->country,
					carrierName: 'DPD',
					latitude: isset($shop['latitude']) ? (float)$shop['latitude'] : null,
					longitude: isset($shop['longitude']) ? (float)$shop['longitude'] : null,
					distanceMetres: isset($shop['distance']) ? (int)round((float)$shop['distance'] * 1000) : null,
					metadata: array_filter([
						'openingHours' => $shop['openingHours'] ?? null,
						'phone'        => $shop['phone'] ?? null,
						'email'        => $shop['email'] ?? null,
					], fn($v) => $v !== null && $v !== ''),
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
			$result = $this->getGateway()->getLabel($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$labelContent = $result['response']['labelContent'] ?? null;
			
			if (empty($labelContent)) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					'missing_label',
					"DPD returned no label content for parcel label number {$parcelId}"
				);
			}
			
			return 'data:application/pdf;base64,' . $labelContent;
		}
		
		/**
		 * Builds a ShipmentState from a DPD ParcelLifeCycle tracking result.
		 * Used by exchange(). The current status entry is the one where isCurrentStatus = true;
		 * we fall back to the last statusInfo entry if none is explicitly marked current.
		 * @param string $parcelId The DPD parcel label number
		 * @param array $trackingResult Decoded tracking response
		 * @return ShipmentState
		 */
		public function buildStateFromTracking(string $parcelId, array $trackingResult): ShipmentState {
			$statusInfos = $trackingResult['statusInfo'] ?? [];
			
			// Normalise single-entry response (XML-to-array may not wrap in array)
			if (isset($statusInfos['status'])) {
				$statusInfos = [$statusInfos];
			}
			
			// Find the entry marked as current; fall back to last entry
			$current = null;
			
			foreach ($statusInfos as $info) {
				if (($info['isCurrentStatus'] ?? 'false') === 'true') {
					$current = $info;
				}
			}
			
			$current ??= end($statusInfos) ?: [];
			
			$statusCode = $current['status'] ?? 'unknown';
			$status = self::STATUS_MAP[$statusCode] ?? ShipmentStatus::Unknown;
			
			// Extract human-readable label text from the nested label/content structure
			$statusMessage = $current['label']['content'] ?? null;
			
			// Location and timestamp from the current status entry
			$location = $current['location']['content'] ?? null;
			$timestamp = $current['date']['content'] ?? null;
			
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
		 * @param array $config
		 * @return array
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
			
			return array_filter([
				'name1'   => $sender['company'] ?? $sender['name'] ?? '',
				'name2'   => $sender['name'] ?? null,
				'street'  => $sender['street'] ?? '',
				'houseNo' => $sender['number'] ?? '',
				'country' => $sender['cc'] ?? 'NL',
				'zipCode' => $sender['postal_code'] ?? '',
				'city'    => $sender['city'] ?? '',
				'phone'   => $sender['phone'] ?? null,
				'email'   => $sender['email'] ?? null,
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
