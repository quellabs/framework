<?php
	
	namespace Quellabs\Shipments\MyParcel;
	
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
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	
	class Driver implements ShipmentProviderInterface {
		
		use GatewayHelpers;
		
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
		 * Maps MyParcel track-trace status codes to our normalised ShipmentStatus values.
		 * Status codes are lowercase strings per the MyParcel API docs (v1.1).
		 * @see https://developer.myparcel.nl/api-reference/06.track-trace.html
		 */
		private const STATUS_MAP = [
			'registered'                        => ShipmentStatus::Created,
			'handed_to_carrier'                 => ShipmentStatus::ReadyToSend,
			'sorting'                           => ShipmentStatus::InTransit,
			'in_transit'                        => ShipmentStatus::InTransit,
			'transit'                           => ShipmentStatus::InTransit,
			'customs'                           => ShipmentStatus::InTransit,
			'out_for_delivery'                  => ShipmentStatus::OutForDelivery,
			'delivered'                         => ShipmentStatus::Delivered,
			'delivered_at_neighbor'             => ShipmentStatus::Delivered,
			'delivered_at_mailbox'              => ShipmentStatus::Delivered,
			'available_for_pickup'              => ShipmentStatus::AwaitingPickup,
			'available_for_pickup_postnl'       => ShipmentStatus::AwaitingPickup,
			'delivery_failed'                   => ShipmentStatus::DeliveryFailed,
			'not_delivered'                     => ShipmentStatus::DeliveryFailed,
			'refused_by_recipient'              => ShipmentStatus::DeliveryFailed,
			'return_to_sender'                  => ShipmentStatus::ReturnedToSender,
			'return_shipment_handed_to_carrier' => ShipmentStatus::ReturnedToSender,
			'destroyed'                         => ShipmentStatus::Destroyed,
			'lost'                              => ShipmentStatus::Lost,
			'unknown'                           => ShipmentStatus::Unknown,
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
		 * @throws ShipmentCreationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			$carrierInfo = self::MODULE_CARRIER_MAP[$request->shippingModule] ?? null;
			
			if ($carrierInfo === null) {
				throw new ShipmentCreationException(
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
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch the response
			$response = $result['response'] ?? [];
			
			// Validate the existence of a parcel id
			$parcelId = $this->arrayGetString($response, 'data.ids.0.id');
			
			if (empty($parcelId)) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_id',
					'MyParcel did not return a valid shipment ID in the creation response'
				);
			}
			
			// Return the ShipmentResult
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: $parcelId,
				reference: $request->reference,
				trackingCode: null,
				trackingUrl: null,
				carrierName: $this->carrierName($carrierInfo['carrierId']),
				rawResponse: $response,
			);
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
			
			$rawCarrierId  = $shipment['carrier_id'] ?? null;
			$carrierId     = is_numeric($rawCarrierId) ? (int)$rawCarrierId : null;
			$parcelId      = $this->normalizeString($shipment['id'] ?? '');
			$reference     = $this->arrayGetString($shipment, 'reference_identifier') ?? '';
			$internalState = $this->arrayGetString($shipment, 'status') ?? 'unknown';
			$status        = self::STATUS_MAP[strtolower($internalState)] ?? ShipmentStatus::Unknown;
			$barcode       = $this->arrayGetString($shipment, 'barcode');
			$postalCode    = $this->arrayGetString($shipment, 'recipient.postal_code') ?? '';
			$trackingUrl   = $barcode !== null ? $this->buildTrackingUrl($barcode, $postalCode, $carrierId) : null;
			
			return new ShipmentState(
				provider: self::DRIVER_NAME,
				parcelId: $parcelId,
				reference: $reference,
				state: $status,
				trackingCode: $barcode,
				trackingUrl: $trackingUrl,
				statusMessage: null,
				internalState: $internalState,
				metadata: array_filter([
					'carrierId'   => $carrierId,
					'carrierName' => $this->carrierName($carrierId),
					'postalCode'  => $postalCode !== '' ? $postalCode : null,
					'weightGrams' => $this->arrayGet($shipment, 'physical_properties.weight'),
				], fn($v) => $v !== null),
			);
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
			
			// Failed or none found. Bail.
			if (empty($data)) {
				return [];
			}
			
			// Fetch carrier
			$carrierName = $this->carrierName(self::MODULE_CARRIER_MAP[$shippingModule]['carrierId'] ?? null) ?? '';
			
			// Decode options
			$options = [];
			
			foreach ($this->arrayGetArray($data, 'delivery') ?? [] as $timeframe) {
				// Invalid timeframe
				if (!is_array($timeframe)) {
					continue;
				}
				
				// Format timeframe date
				$rawDate = $this->arrayGetString($timeframe, 'date') ?? '';
				$date    = $rawDate !== '' ? (\DateTimeImmutable::createFromFormat('Y-m-d', $rawDate) ?: null) : null;
				
				foreach ($this->arrayGetArray($timeframe, 'time') ?? [] as $slot) {
					// Skip invalid slots
					if (!is_array($slot)) {
						continue;
					}
					
					// Add delivery option to list
					$start = substr($this->arrayGetString($slot, 'start') ?? '', 0, 5); // '09:00:00' → '09:00'
					$end   = substr($this->arrayGetString($slot, 'end') ?? '', 0, 5);
					$type  = $this->arrayGetString($slot, 'price_comment') ?? 'standard';
					$label = $this->buildDeliveryLabel($date, $start, $end, $type);
					
					$options[] = new DeliveryOption(
						methodId: $rawDate . ':' . $start . ':' . $end,
						label: $label,
						carrierName: $carrierName,
						deliveryDate: $date,
						windowStart: $start ?: null,
						windowEnd: $end ?: null,
						metadata: array_filter([
							'type'  => $type,
							'price' => $slot['price'] ?? null,
						], fn($v) => $v !== null),
					);
				}
			}
			
			return $options;
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
			
			$carrierName = $this->carrierName(self::MODULE_CARRIER_MAP[$shippingModule]['carrierId'] ?? null) ?? '';
			$options     = [];
			
			foreach ($this->arrayGetArray($data, 'pickup') ?? [] as $location) {
				// Skip invalid locations
				if (!is_array($location)) {
					continue;
				}
				
				// Add location as PickupOption to list
				$locationCode    = $this->arrayGetString($location, 'location_code') ?? '';
				$name            = $this->arrayGetString($location, 'location') ?? '';
				$street          = $this->arrayGetString($location, 'street') ?? '';
				$houseNumber     = $this->arrayGetString($location, 'number') ?? '';
				$postalCode      = $this->arrayGetString($location, 'postal_code') ?? '';
				$city            = $this->arrayGetString($location, 'city') ?? '';
				$country         = $this->arrayGetString($location, 'cc') ?? '';
				$phone           = $this->arrayGetString($location, 'phone_number');
				$comment         = $this->arrayGetString($location, 'comment');
				$openingHours    = $this->arrayGetArray($location, 'opening_hours');
				$retailNetworkId = $this->arrayGetString($location, 'retail_network_id');
				$latitude        = $this->toFloat($this->arrayGet($location, 'latitude'), 0.0) ?: null;
				$longitude       = $this->toFloat($this->arrayGet($location, 'longitude'), 0.0) ?: null;
				$rawDistance     = $this->arrayGet($location, 'distance');
				$distanceMetres  = $rawDistance !== null ? $this->toInt($rawDistance) : null;
				
				$options[] = new PickupOption(
					locationCode: $locationCode,
					name: $name,
					street: $street,
					houseNumber: $houseNumber,
					postalCode: $postalCode,
					city: $city,
					country: $country,
					carrierName: $carrierName,
					latitude: $latitude,
					longitude: $longitude,
					distanceMetres: $distanceMetres,
					metadata: array_filter([
						'phone'           => $phone,
						'comment'         => $comment,
						'openingHours'    => $openingHours,
						'retailNetworkId' => $retailNetworkId,
					], fn($value) => $value !== null),
				);
			}
			
			return $options;
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
			
			// If that failed, bail
			if ($result['request']['result'] === 0) {
				return [];
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
		
		/**
		 * Builds a human-readable label for a delivery timeslot.
		 * @param \DateTimeImmutable|null $date
		 * @param string $start e.g. '09:00'
		 * @param string $end e.g. '12:00'
		 * @param string $type e.g. 'standard', 'morning', 'avond'
		 * @return string
		 */
		private function buildDeliveryLabel(?\DateTimeImmutable $date, string $start, string $end, string $type): string {
			$dateStr = $date ? $date->format('d-m-Y') : '';
			
			return match ($type) {
				'morning' => trim("{$dateStr} Morning {$start}–{$end}"),
				'avond' => trim("{$dateStr} Evening {$start}–{$end}"),
				default => trim("{$dateStr} {$start}–{$end}"),
			};
		}
		
		/**
		 * Constructs a public track-and-trace URL from a barcode.
		 * @param string $barcode
		 * @param string $postalCode
		 * @param int|null $carrierId
		 * @return string|null
		 */
		private function buildTrackingUrl(string $barcode, string $postalCode, ?int $carrierId): ?string {
			return match ($carrierId) {
				1 => "https://postnl.nl/tracktrace/?B={$barcode}&P=" . rawurlencode($postalCode) . "&D=NL&T=C",
				2 => "https://track.bpost.be/bpb/sites/track/index.html#/tracking?barcode={$barcode}",
				default => null,
			};
		}
		
		/**
		 * Returns a human-readable carrier name from a MyParcel carrier ID.
		 * @param int|null $carrierId
		 * @return string|null
		 */
		private function carrierName(?int $carrierId): ?string {
			return match ($carrierId) {
				1 => 'PostNL',
				2 => 'bpost',
				3 => 'CheapCargo',
				4 => 'DHL',
				5 => 'DHL For You',
				8 => 'DPD',
				default => null,
			};
		}
	}