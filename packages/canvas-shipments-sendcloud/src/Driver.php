<?php
	
	namespace Quellabs\Shipments\SendCloud;
	
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
		const DRIVER_NAME = 'sendcloud';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var SendCloudGateway|null
		 */
		private ?SendCloudGateway $gateway = null;
		
		/**
		 * Maps our internal module names to the SendCloud carrier names used in service point
		 * queries. A module may cover multiple carriers (e.g. 'sendcloud_multi' could list all).
		 *
		 * Add entries here whenever a new module is introduced in getMetadata().
		 */
		private const MODULE_CARRIER_MAP = [
			'sendcloud_postnl'  => ['postnl'],
			'sendcloud_dhl'     => ['dhl'],
			'sendcloud_dpd'     => ['dpd'],
			'sendcloud_ups'     => ['ups'],
			'sendcloud_bpost'   => ['bpost'],
			'sendcloud_mondial' => ['mondial_relay'],
			'sendcloud_multi'   => ['postnl', 'dhl', 'dpd', 'ups', 'bpost', 'mondial_relay'],
		];
		
		/**
		 * Maps SendCloud parcel status IDs to our normalised ShipmentStatus values.
		 * SendCloud status IDs are integers; partial mapping is intentional — unmapped
		 * IDs fall through to ShipmentStatus::Unknown.
		 *
		 * @see https://docs.sendcloud.com/api/v2/#parcel-statuses
		 */
		private const STATUS_MAP = [
			1  => ShipmentStatus::Created,
			2  => ShipmentStatus::ReadyToSend,
			3  => ShipmentStatus::InTransit,
			4  => ShipmentStatus::Delivered,
			5  => ShipmentStatus::DeliveryFailed,
			6  => ShipmentStatus::AwaitingPickup,
			7  => ShipmentStatus::ReturnedToSender,
			11 => ShipmentStatus::InTransit,
			12 => ShipmentStatus::InTransit,
			13 => ShipmentStatus::OutForDelivery,
			91 => ShipmentStatus::Cancelled,
			92 => ShipmentStatus::Unknown,
			93 => ShipmentStatus::Lost,           // Lost in transit
			99 => ShipmentStatus::Unknown,
		];
		
		/**
		 * Returns discovery metadata for this provider.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_CARRIER_MAP),
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
				'public_key'        => '',
				'secret_key'        => '',
				'partner_id'        => '',
				'webhook_secret'    => '',
				'sender_address'    => [],
				'from_country'      => 'NL',
				'pickup_radius_km'  => 5.0,
				'geocoding_api_key' => '',
			];
		}
		
		/**
		 * Creates a parcel via SendCloud and returns a structured result.
		 *
		 * Labels are not included in the result. The SendCloud API is instructed not to
		 * generate a label at creation time (request_label=false). Call getLabelUrl()
		 * explicitly when you need the label.
		 *
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 * @throws ShipmentCreationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			// Validate presence of methodId
			if ($request->methodId === null) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_method_id',
					'SendCloud requires a methodId. Fetch one from getDeliveryOptions() first.'
				);
			}
			
			// Build payload
			$address = $request->deliveryAddress;
			
			$payload = [
				'parcel' => array_filter([
					'name'                 => $address->name,
					'company_name'         => $address->company,
					'address'              => $address->street,
					'house_number'         => $address->houseNumber . (!empty($address->houseNumberSuffix) ? ' ' . $address->houseNumberSuffix : ''),
					'city'                 => $address->city,
					'postal_code'          => $address->postalCode,
					'country'              => ['iso_2' => $address->country],
					'email'                => $address->email,
					'telephone'            => $address->phone,
					'order_number'         => $request->reference,
					'weight'               => round($request->weightGrams / 1000, 3),
					'shipment'             => ['id' => $request->methodId],
					'insured_value'        => $request->declaredValueCents > 0 ? $request->declaredValueCents : null,
					'to_service_point'     => $request->servicePointId,
					'request_label'        => false,
					'apply_shipping_rules' => true,
				], fn($v) => $v !== null && $v !== '' && $v !== []),
			];
			
			// Merge extra data into payload
			if (!empty($request->extraData)) {
				$senderKeys = ['name', 'company_name', 'address', 'house_number', 'city', 'postal_code', 'country', 'email', 'telephone'];
				$payload['parcel'] = array_merge($payload['parcel'], array_diff_key($request->extraData, array_flip($senderKeys)));
			}
			
			// Sender address is always applied last so it cannot be overridden.
			$senderAddress = array_filter($this->getConfig()['sender_address'] ?? []);
			
			if (!empty($senderAddress)) {
				$payload['parcel'] = array_merge($payload['parcel'], $senderAddress);
			}
			
			// Call API
			$result = $this->getGateway()->createParcel($payload);
			
			// If failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch parcel data
			$parcel = $result['response']['parcel'];
			
			// Return result
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: (string)$parcel['id'],
				reference: $request->reference,
				trackingCode: $parcel['tracking_number'] ?? null,
				trackingUrl: $parcel['tracking_url'] ?? null,
				carrierName: $parcel['carrier']['name'] ?? null,
				rawResponse: $parcel,
			);
		}
		
		/**
		 * Cancels a parcel via SendCloud.
		 * @param CancelRequest $request
		 * @return CancelResult
		 * @throws ShipmentCancellationException
		 */
		public function cancel(CancelRequest $request): CancelResult {
			// Send cancel request to API
			$result = $this->getGateway()->cancelParcel($request->parcelId);
			
			// If that failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new ShipmentCancellationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Check if the cancellation was accepted
			$isAccepted = ($result['response']['status'] ?? '') === 'cancelled';
			
			// Return response
			return new CancelResult(
				provider: self::DRIVER_NAME,
				parcelId: $request->parcelId,
				reference: $request->reference,
				accepted: $isAccepted,
				message: $isAccepted ? null : ($result['response']['message'] ?? null),
			);
		}
		
		/**
		 * Fetches the current state of a parcel from SendCloud.
		 * @param string $parcelId
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function exchange(string $parcelId): ShipmentState {
			// Call API to fetch parcel data
			$result = $this->getGateway()->getParcel($parcelId);
			
			// If that failed, throw an exception
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Build and return ShipmentState
			return $this->buildStateFromParcel($result['response']['parcel']);
		}
		
		/**
		 * Returns normalized home delivery options for the given module.
		 *
		 * Filters the SendCloud shipping methods list to those whose service_point_input
		 * is 'none' (i.e. home delivery, not pickup). $address is not used — SendCloud
		 * shipping methods are static and independent of recipient location.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return DeliveryOption[]
		 */
		public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			return array_values(array_map(
				fn(array $method) => $this->normaliseDeliveryMethod($method),
				array_filter(
					$this->fetchFilteredMethods($shippingModule),
					fn(array $method) => ($method['service_point_input'] ?? 'none') === 'none'
				)
			));
		}
		
		/**
		 * Returns normalised pickup options for the given module.
		 *
		 * Filters the SendCloud shipping methods list to those that require a service point
		 * (service_point_input !== 'none'). The $address is not used for method retrieval
		 * but is available for service point proximity queries if needed in future.
		 *
		 * Note: SendCloud service point locations require a separate getServicePoints() call
		 * on the gateway. This method returns the methods that support pickup; actual
		 * location search is out of scope here and should be handled at the checkout layer.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return PickupOption[]
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			if ($address === null) {
				return [];
			}
			
			$carriers = self::MODULE_CARRIER_MAP[$shippingModule] ?? [];
			
			if (empty($carriers)) {
				return [];
			}
			
			$center = $this->geocodeAddress($address);
			
			if ($center === null) {
				return [];
			}
			
			$radiusKm = $this->getConfig()['pickup_radius_km'] ?? 5.0;
			[$swLat, $swLng, $neLat, $neLng] = $this->boundingBox($center['lat'], $center['lng'], $radiusKm);
			
			$result = $this->getGateway()->getServicePoints(
				$carriers,
				$address->country,
				$neLat,
				$neLng,
				$swLat,
				$swLng
			);
			
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			$points = $result['response'] ?? [];
			
			return array_values(array_map(
				fn(array $point) => $this->normaliseServicePoint($point),
				$points
			));
		}
		
		/**
		 * Verifies the HMAC-SHA256 signature on an incoming SendCloud webhook.
		 * @param string $rawBody The raw (un-decoded) request body
		 * @param string $signature Value of the Sendcloud-Signature header
		 * @return bool
		 */
		public function verifyWebhookSignature(string $rawBody, string $signature): bool {
			return $this->getGateway()->verifyWebhookSignature($rawBody, $signature, $this->getConfig()['webhook_secret']);
		}
		
		/**
		 * Returns the URL where the label PDF for the given parcel can be downloaded.
		 * @param string $parcelId
		 * @return string
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
			
			$url = $result['response']['label']['label_printer']
				?? $result['response']['label']['normal_printer'][0]
				?? null;
			
			if ($url === null) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					'missing_url',
					"SendCloud returned no label URL for parcel {$parcelId}"
				);
			}
			
			return $url;
		}
		
		/**
		 * Builds a ShipmentState from a raw parcel array returned by the SendCloud API.
		 * Used by both exchange() and the controller's webhook handler.
		 * @param array $parcel The 'parcel' key from the SendCloud API response
		 * @return ShipmentState
		 */
		public function buildStateFromParcel(array $parcel): ShipmentState {
			$statusId = (int)($parcel['status']['id'] ?? 92);
			$statusLabel = $parcel['status']['message'] ?? null;
			$internalState = $parcel['status']['id'] . ':' . ($parcel['status']['message'] ?? 'unknown');
			$status = self::STATUS_MAP[$statusId] ?? ShipmentStatus::Unknown;
			
			return new ShipmentState(
				provider: self::DRIVER_NAME,
				parcelId: (string)$parcel['id'],
				reference: $parcel['order_number'] ?? '',
				state: $status,
				trackingCode: $parcel['tracking_number'] ?? null,
				trackingUrl: $parcel['tracking_url'] ?? null,
				statusMessage: $statusLabel,
				internalState: $internalState,
				metadata: array_filter([
					'carrierId'      => $parcel['carrier']['id'] ?? null,
					'carrierName'    => $parcel['carrier']['name'] ?? null,
					'cachedLabelUrl' => $parcel['label']['label_printer'] ?? null,
					'servicePointId' => $parcel['to_service_point'] ?? null,
					'weightKg'       => $parcel['weight'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Lazily instantiates and returns the SendCloud gateway.
		 * @return SendCloudGateway
		 */
		private function getGateway(): SendCloudGateway {
			return $this->gateway ??= new SendCloudGateway($this);
		}
		
		/**
		 * Fetches shipping methods from the gateway and filters them by carrier module.
		 * Shared by getDeliveryOptions() and getPickupOptions().
		 * @param string $shippingModule
		 * @return array Raw method arrays from the SendCloud API
		 */
		private function fetchFilteredMethods(string $shippingModule): array {
			// Fetch shipping methods from API
			$result = $this->getGateway()->getShippingMethods($this->getConfig()['from_country'] ?? null);
			
			// If that failed, return an empty array
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			// Fetch shipping methods from result
			$carriers = self::MODULE_CARRIER_MAP[$shippingModule] ?? [];
			$methods = $result['response']['shipping_methods'] ?? [];
			
			// If none, return empty array
			if (empty($carriers)) {
				return $methods;
			}
			
			// Filter methods by carrier
			return array_values(array_filter($methods, function (array $method) use ($carriers): bool {
				return in_array(strtolower($method['carrier'] ?? ''), $carriers, true);
			}));
		}
		
		/**
		 * Normalises a raw SendCloud shipping method array into a DeliveryOption.
		 * @param array $method
		 * @return DeliveryOption
		 */
		private function normaliseDeliveryMethod(array $method): DeliveryOption {
			return new DeliveryOption(
				methodId: (string)$method['id'],
				label: $method['name'] ?? '',
				carrierName: $method['carrier'] ?? '',
				metadata: array_filter([
					'minWeightGrams' => isset($method['min_weight']) ? (int)round($method['min_weight'] * 1000) : null,
					'maxWeightGrams' => isset($method['max_weight']) ? (int)round($method['max_weight'] * 1000) : null,
					'price'          => $method['price'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Geocodes a ShipmentAddress to a lat/lng center using Nominatim.
		 * Returns null if geocoding fails or returns no results.
		 * @param ShipmentAddress $address
		 * @return array{lat: float, lng: float}|null
		 */
		private function geocodeAddress(ShipmentAddress $address): ?array {
			$result = $this->getGateway()->geocodeAddress(
				$address->postalCode,
				$address->country,
				$address->city,
			);
			
			if ($result['request']['result'] === 0) {
				return null;
			}
			
			return $result['response'];
		}
		
		/**
		 * Computes a bounding box around a center point given a radius in km.
		 * Uses the equirectangular approximation — accurate enough for <50 km radii.
		 * Returns [swLat, swLng, neLat, neLng].
		 * @param float $lat
		 * @param float $lng
		 * @param float $radiusKm
		 * @return array{float, float, float, float}
		 */
		private function boundingBox(float $lat, float $lng, float $radiusKm): array {
			$latDelta = $radiusKm / 111.0;
			$lngDelta = $radiusKm / (111.0 * cos(deg2rad($lat)));
			
			return [
				$lat - $latDelta, // swLat
				$lng - $lngDelta, // swLng
				$lat + $latDelta, // neLat
				$lng + $lngDelta, // neLng
			];
		}
		
		/**
		 * Normalizes a raw SendCloud service point array into a PickupOption.
		 * @param array $point
		 * @return PickupOption
		 */
		private function normaliseServicePoint(array $point): PickupOption {
			return new PickupOption(
				locationCode: (string)($point['id'] ?? ''),
				name: $point['name'] ?? '',
				street: $point['street'] ?? '',
				houseNumber: (string)($point['house_number'] ?? ''),
				postalCode: $point['postal_code'] ?? '',
				city: $point['city'] ?? '',
				country: $point['country'] ?? '',
				carrierName: $point['carrier'] ?? '',
				latitude: isset($point['latitude']) ? (float)$point['latitude'] : null,
				longitude: isset($point['longitude']) ? (float)$point['longitude'] : null,
				distanceMetres: isset($point['distance']) ? (int)$point['distance'] : null,
				metadata: array_filter([
					'openingHours' => $point['opening_hours'] ?? null,
					'extraInfo'    => $point['extra_info'] ?? null,
					'phone'        => $point['phone_number'] ?? null,
				], fn($v) => $v !== null),
			);
		}
	}