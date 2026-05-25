<?php
	
	namespace Quellabs\Shipments\MyParcel;
	
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\DeliveryOption;
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentOptionException;
	use Quellabs\Shipments\Contracts\ShipmentCancellationException;
	use Quellabs\Shipments\Contracts\ShipmentInitiationException;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\Shipments\Contracts\ShipmentLabelException;
	use Quellabs\Shipments\Contracts\ShipmentProviderInterface;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	use Quellabs\Shipments\MyParcel\Transformers\DeliveryOptionTransformer;
	use Quellabs\Shipments\MyParcel\Transformers\PickupOptionTransformer;
	use Quellabs\Shipments\MyParcel\Transformers\ShipmentResultTransformer;
	use Quellabs\Shipments\MyParcel\Transformers\ShipmentStateTransformer;
	
	class Driver implements ShipmentProviderInterface {
		
		use GatewayHelpers;
		use MyParcelHelpers;
		
		/**
		 * Driver name — stored in ShipmentResult::$provider and ShipmentState::$provider.
		 * Used by ShipmentRouter::exchange() to re-resolve this driver later.
		 */
		const string DRIVER_NAME = 'myparcel';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var MyParcelGateway|null
		 */
		private ?MyParcelGateway $gateway = null;
		
		/**
		 * Maps our internal module names to MyParcel carrier IDs.
		 *
		 * Carrier IDs per MyParcel documentation:
		 *   1 = PostNL
		 *   2 = bpost       (BE only)
		 *   3 = CheapCargo  (NL only)
		 *   4 = DHL
		 *   5 = DHL For You (NL only)
		 *   8 = DPD
		 *
		 * @see https://developer.myparcel.nl/api-reference/02.shipments.html
		 */
		private const MODULE_CARRIER_MAP = [
			'myparcel_postnl'     => ['carrierId' => 1, 'region' => 'nl'],
			'myparcel_cheapcargo' => ['carrierId' => 3, 'region' => 'nl'],
			'myparcel_dhl'        => ['carrierId' => 4, 'region' => 'nl'],
			'myparcel_dhlforyou'  => ['carrierId' => 5, 'region' => 'nl'],
			'myparcel_dpd'        => ['carrierId' => 8, 'region' => 'nl'],
			'myparcel_bpost'      => ['carrierId' => 2, 'region' => 'be'],
		];
		
		/**
		 * Maps the carrier-neutral packageType string from ShipmentRequest to MyParcel's integer codes.
		 * @see https://developer.myparcel.nl/api-reference/02.shipments.html
		 */
		private const PACKAGE_TYPE_MAP = [
			'parcel'        => 1,
			'mailbox'       => 2,
			'letter'        => 3,
			'digital_stamp' => 4,
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
				'api_key'        => '',
				'api_key_test'   => '',
				'region'         => 'nl',
				'test_mode'      => false,
				'sender_address' => [],
			];
		}
		
		/**
		 * Creates a parcel via MyParcel and returns a structured result.
		 *
		 * The creation response contains only the internal shipment ID. Tracking code is assigned
		 * asynchronously by the carrier — use exchange() or the webhook to obtain it later.
		 * Labels are not included in the result; call getLabelUrl() explicitly when needed.
		 *
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 * @throws ShipmentInitiationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			$carrierInfo = self::MODULE_CARRIER_MAP[$request->shippingModule] ?? null;
			
			if ($carrierInfo === null) {
				throw new ShipmentInitiationException(
					self::DRIVER_NAME,
					'unknown_module',
					"Unknown shipping module '{$request->shippingModule}'"
				);
			}
			
			$shipmentPayload = array_filter([
				'carrier'              => $carrierInfo['carrierId'],
				'reference_identifier' => $request->reference,
				'recipient'            => $this->buildRecipient($request->deliveryAddress),
				'options'              => array_filter([
					'package_type'      => self::PACKAGE_TYPE_MAP[$request->packageType ?? 'parcel'] ?? 1,
					'delivery_type'     => 2,
					'label_description' => $request->description,
					'insurance'         => $request->declaredValueCents > 0 ? [
						'amount'   => $request->declaredValueCents,
						'currency' => $request->currency,
					] : null,
				], fn($v) => $v !== null && $v !== ''),
				'physical_properties'  => [
					'weight' => $request->weightGrams,
				],
				'pickup'               => $request->servicePointId !== null ? [
					'location_code' => $request->servicePointId,
				] : null,
			], fn($v) => $v !== null && $v !== []);
			
			if (!empty($request->extraData)) {
				$shipmentPayload = array_merge($shipmentPayload, $request->extraData);
			}
			
			// Call the API
			$result = $this->getGateway()->createParcel([
				'data' => ['shipments' => [$shipmentPayload]],
			]);
			
			// If that failed, throw error
			if ($result['request']['result'] === 0) {
				throw new ShipmentInitiationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Validate the existence of a parcel id before transforming
			$response = $result['response'] ?? [];
			$parcelId = $this->arrayGetString($response, 'data.ids.0.id');
			
			if (empty($parcelId)) {
				throw new ShipmentInitiationException(
					self::DRIVER_NAME,
					'missing_id',
					'MyParcel did not return a valid shipment ID in the creation response'
				);
			}
			
			// Transform response to ShipmentResult
			return (new ShipmentResultTransformer(self::DRIVER_NAME, $carrierInfo['carrierId'], $request->reference))
				->transform($response);
		}
		
		/**
		 * MyParcel does not provide a cancellation endpoint in API v1.1.
		 * Parcels must be deleted manually via the MyParcel panel before carrier pickup.
		 *
		 * @param CancelRequest $request
		 * @return CancelResult
		 * @throws ShipmentCancellationException always
		 */
		public function cancel(CancelRequest $request): CancelResult {
			throw new ShipmentCancellationException(
				self::DRIVER_NAME,
				'not_supported',
				'MyParcel API v1.1 does not support programmatic parcel cancellation. ' .
				'Parcels must be deleted manually via the MyParcel panel before carrier pickup.'
			);
		}
		
		/**
		 * Fetches the current state of a parcel from MyParcel via the shipments endpoint.
		 *
		 * The shipments endpoint is used (by ID) rather than tracktraces (by barcode) because
		 * the barcode may not yet be assigned immediately after creation.
		 *
		 * @param string $parcelId The MyParcel internal shipment ID from ShipmentResult::$parcelId
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function exchange(string $parcelId): ShipmentState {
			$result = $this->getGateway()->getShipment($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// arrayGetArray resolves response.data.shipments.0 and confirms it is an array
			$shipment = $this->arrayGetArray($result['response'] ?? [], 'data.shipments.0');
			
			if ($shipment === null) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					'not_found',
					"MyParcel returned no shipment data for parcel ID {$parcelId}"
				);
			}
			
			// Transform raw shipment record to ShipmentState
			return (new ShipmentStateTransformer(self::DRIVER_NAME))
				->transform($shipment);
		}
		
		/**
		 * Returns normalised home delivery options for the given module.
		 *
		 * Calls the MyParcel delivery_options endpoint and maps the 'delivery' array.
		 * Each timeslot becomes one DeliveryOption. Returns an empty array if no address
		 * is provided, as MyParcel requires a recipient location to compute availability.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return DeliveryOption[]
		 */
		public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			// Fetch delivery data from API
			$data = $this->fetchDeliveryData($shippingModule, $address);
			
			// None found. Bail.
			if (empty($data)) {
				return [];
			}
			
			// Resolve carrier name once and pass it to the transformer
			$carrierName = $this->carrierName(self::MODULE_CARRIER_MAP[$shippingModule]['carrierId'] ?? null) ?? '';
			
			// Transform raw timeframes to DeliveryOption list
			return (new DeliveryOptionTransformer($carrierName))
				->transformAll($this->arrayGetArray($data, 'delivery') ?? []);
		}
		
		/**
		 * Returns normalised pickup point options for the given module.
		 *
		 * Calls the MyParcel delivery_options endpoint and maps the 'pickup' array.
		 * Each location becomes one PickupOption. Returns an empty array if no address
		 * is provided, as MyParcel requires a search origin to find nearby points.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return PickupOption[]
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			$data = $this->fetchDeliveryData($shippingModule, $address);
			
			if (empty($data)) {
				return [];
			}
			
			// Resolve carrier name once and pass it to the transformer
			$carrierName = $this->carrierName(self::MODULE_CARRIER_MAP[$shippingModule]['carrierId'] ?? null) ?? '';
				
			// Transform raw pickup locations to PickupOption list
			return (new PickupOptionTransformer($carrierName))
				->transformAll($this->arrayGetArray($data, 'pickup') ?? []);
		}
		
		/**
		 * Returns the URL where the label PDF for the given parcel can be downloaded.
		 * @param string $parcelId
		 * @return string
		 * @throws ShipmentLabelException
		 */
		public function getLabelUrl(string $parcelId): string {
			// Use API to fetch label
			$result = $this->getGateway()->getLabel($parcelId);
			
			// If that failed, throw
			if ($result['request']['result'] === 0) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch url
			$url = $this->arrayGetString($result['response'] ?? [], 'data.pdfs.url');
			
			// If none found, throw
			if ($url === null) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					'missing_url',
					"MyParcel returned no label URL for parcel {$parcelId}"
				);
			}
			
			// Return url
			return $url;
		}
		
		/**
		 * Lazily instantiates and returns the MyParcel gateway.
		 * @return MyParcelGateway
		 */
		private function getGateway(): MyParcelGateway {
			return $this->gateway ??= new MyParcelGateway($this);
		}
		
		/**
		 * Fetches and returns the raw delivery_options data for the given module and address.
		 * Shared by getDeliveryOptions() and getPickupOptions() to avoid a double API call.
		 * Returns an empty array on missing address, unknown module, or API error.
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return array<string, mixed> Keys: 'delivery', 'pickup'
		 * @throws ShipmentOptionException
		 */
		private function fetchDeliveryData(string $shippingModule, ?ShipmentAddress $address): array {
			// If no address passed there is no point in calling the API
			if ($address === null) {
				return [];
			}
			
			// Fetch the carrier
			$carrierInfo = self::MODULE_CARRIER_MAP[$shippingModule] ?? null;
			
			// If none found, we can't continue
			if ($carrierInfo === null) {
				return [];
			}
			
			// Fetch the options from the API
			$result = $this->getGateway()->getDeliveryOptions(
				$carrierInfo['carrierId'],
				$address->postalCode,
				$address->houseNumber,
				$address->city,
				$address->country
			);
			
			// If failed, throw error
			if ($result['request']['result'] === 0) {
				throw new ShipmentOptionException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Return response
			return $this->arrayGetArray($result['response'] ?? [], 'data') ?? [];
		}
		
		/**
		 * Builds the recipient block for the MyParcel shipment payload.
		 * @param ShipmentAddress $address
		 * @return array<string, mixed>
		 */
		private function buildRecipient(ShipmentAddress $address): array {
			return array_filter([
				'company'       => $address->company,
				'person'        => $address->name,
				'street'        => $address->street,
				'number'        => $address->houseNumber,
				'number_suffix' => $address->houseNumberSuffix,
				'postal_code'   => $address->postalCode,
				'city'          => $address->city,
				'region'        => $address->region,
				'cc'            => $address->country,
				'email'         => $address->email,
				'phone'         => $address->phone,
			], fn($v) => $v !== null && $v !== '');
		}
	}