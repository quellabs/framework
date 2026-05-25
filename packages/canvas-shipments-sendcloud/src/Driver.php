<?php
	
	namespace Quellabs\Shipments\SendCloud;
	
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\DeliveryOption;
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentCancellationException;
	use Quellabs\Shipments\Contracts\ShipmentInitiationException;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\Shipments\Contracts\ShipmentLabelException;
	use Quellabs\Shipments\Contracts\ShipmentProviderInterface;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\SendCloud\Transformer\ParcelTransformer;
	use Quellabs\Shipments\SendCloud\Transformer\ShippingMethodTransformer;
	
	class Driver implements ShipmentProviderInterface {
		
		use GatewayHelpers;
		
		/**
		 * Driver name — stored in ShipmentResult::$provider and ShipmentState::$provider.
		 * Used by ShipmentRouter::exchange() to re-resolve this driver later.
		 */
		const string DRIVER_NAME = 'sendcloud';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
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
		private const array MODULE_CARRIER_MAP = [
			'sendcloud_postnl'  => ['postnl'],
			'sendcloud_dhl'     => ['dhl'],
			'sendcloud_dpd'     => ['dpd'],
			'sendcloud_ups'     => ['ups'],
			'sendcloud_bpost'   => ['bpost'],
			'sendcloud_mondial' => ['mondial_relay'],
			'sendcloud_multi'   => ['postnl', 'dhl', 'dpd', 'ups', 'bpost', 'mondial_relay'],
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
		 * @return array<string, mixed>
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
		 * @throws ShipmentInitiationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			// Validate presence of methodId
			if ($request->methodId === null) {
				throw new ShipmentInitiationException(
					self::DRIVER_NAME,
					'missing_method_id',
					'SendCloud requires a methodId. Fetch one from getDeliveryOptions() first.'
				);
			}
			
			// Build payload and call API
			$transformer = new ParcelTransformer(self::DRIVER_NAME);
			$rawSenderAddress = $this->getConfig()['sender_address'] ?? [];
			$senderAddress = array_filter(is_array($rawSenderAddress) ? $rawSenderAddress : []);
			$payload = $transformer->fromRequest($request, $senderAddress);
			$result = $this->getGateway()->createParcel($payload);
			
			// If failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new ShipmentInitiationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch parcel data — the API always returns a 'parcel' object on success
			$response = $result['response'] ?? [];
			$parcel = $this->arrayGetArray($response, 'parcel') ?? [];
			
			return $transformer->toShipmentResult($parcel, $request);
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
				message: $isAccepted ? null : $this->arrayGetString($result['response'] ?? [], 'message'),
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
			$response = $result['response'] ?? [];
			$parcel = $this->arrayGetArray($response, 'parcel') ?? [];
			return (new ParcelTransformer(self::DRIVER_NAME))->toShipmentState($parcel);
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
			$transformer = new ShippingMethodTransformer();
			
			return array_values(array_map(
				fn(array $method) => $transformer->toDeliveryOption($method),
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
			
			$radiusKm = $this->toFloat($this->getConfig()['pickup_radius_km'] ?? null, 5.0);
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
			
			$rawPoints = $result['response'] ?? [];
			$points = is_array($rawPoints) ? $rawPoints : [];
			$transformer = new ShippingMethodTransformer();
			
			return array_values(array_map(
				fn(mixed $point) => $transformer->toPickupOption($point),
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
			$webhookSecret = $this->normalizeString($this->getConfig()['webhook_secret'] ?? '');
			return $this->getGateway()->verifyWebhookSignature($rawBody, $signature, $webhookSecret);
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
			
			$response = $result['response'] ?? [];
			
			// Prefer the label-printer URL; fall back to the first normal-printer URL
			$url = $this->arrayGetString($response, 'label.label_printer');
			
			if ($url === null) {
				$normalPrinter = $this->arrayGetArray($response, 'label.normal_printer') ?? [];
				$first = $normalPrinter[0] ?? null;
				$url = is_string($first) ? $first : null;
			}
			
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
		 * @param array<string, mixed> $parcel The 'parcel' key from the SendCloud API response
		 * @return ShipmentState
		 */
		public function buildStateFromParcel(array $parcel): ShipmentState {
			return (new ParcelTransformer(self::DRIVER_NAME))->toShipmentState($parcel);
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
		 * @return list<array<string, mixed>> Raw method arrays from the SendCloud API
		 */
		private function fetchFilteredMethods(string $shippingModule): array {
			// Fetch shipping methods from API
			$fromCountry = $this->arrayGetString($this->getConfig(), 'from_country');
			$result = $this->getGateway()->getShippingMethods($fromCountry);
			
			// If that failed, return an empty array
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			// Fetch shipping methods from result — guard as list<array<string, mixed>>
			$carriers = self::MODULE_CARRIER_MAP[$shippingModule] ?? [];
			$rawMethods = $this->arrayGetArray($result['response'] ?? [], 'shipping_methods') ?? [];
			
			// Keep only entries that are arrays (malformed entries are skipped)
			/** @var list<array<string, mixed>> $methods */
			$methods = array_values(array_filter($rawMethods, fn(mixed $m): bool => is_array($m)));
			
			// If none, return empty array
			if (empty($carriers)) {
				return $methods;
			}
			
			// Filter methods by carrier
			return array_values(array_filter($methods, function (array $method) use ($carriers): bool {
				return in_array(strtolower($this->normalizeString($method['carrier'] ?? '')), $carriers, true);
			}));
		}
		
		/**
		 * Geocodes a ShipmentAddress to a lat/lng center using Nominatim.
		 * Returns null if geocoding fails or returns no results.
		 * @param ShipmentAddress $address
		 * @return array{lat: float, lng: float}|null
		 */
		private function geocodeAddress(ShipmentAddress $address): ?array {
			// Call API to geocode the address
			$result = $this->getGateway()->geocodeAddress(
				$address->postalCode,
				$address->country,
				$address->city,
			);
			
			// If that failed return error
			if ($result['request']['result'] === 0) {
				return null;
			}
			
			// Fetch response
			$response = $result['response'] ?? [];
			
			// Validate response
			if (!isset($response['lat']) || !isset($response['lng'])) {
				return null;
			}
			
			// Return response data
			return ['lat' => $this->toFloat($response['lat']), 'lng' => $this->toFloat($response['lng'])];
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
	}