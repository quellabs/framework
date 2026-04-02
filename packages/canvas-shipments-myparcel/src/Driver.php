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
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	
	class Driver implements ShipmentProviderInterface {
		
		/**
		 * Driver name — stored in ShipmentResult::$provider and ShipmentState::$provider.
		 * Used by ShipmentRouter::exchange() to re-resolve this driver later.
		 */
		const DRIVER_NAME = 'myparcel';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array
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
				'api_key'        => '',
				'api_key_test'   => '',
				'region'         => 'nl',
				'mode'           => 'live',
				'sender_address' => [],
				'package_type'   => 1,
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
					'package_type'      => $this->getConfig()['package_type'],
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
			
			$result = $this->getGateway()->createParcel([
				'data' => ['shipments' => [$shipmentPayload]],
			]);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$parcelId = (string)($result['response']['data']['ids'][0]['id'] ?? '');
			
			if ($parcelId === '') {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_id',
					'MyParcel did not return a shipment ID in the creation response'
				);
			}
			
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: $parcelId,
				reference: $request->reference,
				trackingCode: null,
				trackingUrl: null,
				carrierName: $this->carrierName($carrierInfo['carrierId']),
				rawResponse: $result['response'],
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
			
			$shipment = $result['response']['data']['shipments'][0] ?? null;
			
			if ($shipment === null) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					'not_found',
					"MyParcel returned no shipment data for parcel ID {$parcelId}"
				);
			}
			
			return $this->buildStateFromShipment($shipment);
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
			$data = $this->fetchDeliveryData($shippingModule, $address);
			
			if (empty($data)) {
				return [];
			}
			
			$carrierName = $this->carrierName(self::MODULE_CARRIER_MAP[$shippingModule]['carrierId'] ?? null) ?? '';
			$options = [];
			
			foreach ($data['delivery'] ?? [] as $timeframe) {
				$date = \DateTimeImmutable::createFromFormat('Y-m-d', $timeframe['date']) ?: null;
				
				foreach ($timeframe['time'] ?? [] as $slot) {
					$start = substr($slot['start'] ?? '', 0, 5); // '09:00:00' → '09:00'
					$end = substr($slot['end'] ?? '', 0, 5);
					$type = $slot['price_comment'] ?? 'standard';
					
					$options[] = new DeliveryOption(
						methodId: $timeframe['date'] . ':' . $start . ':' . $end,
						label: $this->buildDeliveryLabel($date, $start, $end, $type),
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
			$options = [];
			
			foreach ($data['pickup'] ?? [] as $location) {
				$options[] = new PickupOption(
					locationCode: $location['location_code'],
					name: $location['location'] ?? '',
					street: $location['street'] ?? '',
					houseNumber: $location['number'] ?? '',
					postalCode: $location['postal_code'] ?? '',
					city: $location['city'] ?? '',
					country: $location['cc'] ?? '',
					carrierName: $carrierName,
					latitude: isset($location['latitude']) ? (float)$location['latitude'] : null,
					longitude: isset($location['longitude']) ? (float)$location['longitude'] : null,
					distanceMetres: isset($location['distance']) ? (int)$location['distance'] : null,
					metadata: array_filter([
						'phone'           => $location['phone_number'] ?? null,
						'comment'         => $location['comment'] ?? null,
						'openingHours'    => $location['opening_hours'] ?? null,
						'retailNetworkId' => $location['retail_network_id'] ?? null,
					], fn($v) => $v !== null && $v !== []),
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
			$result = $this->getGateway()->getLabel($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$url = $result['response']['data']['pdfs']['url'] ?? null;
			
			if ($url === null) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					'missing_url',
					"MyParcel returned no label URL for parcel {$parcelId}"
				);
			}
			
			return $url;
		}
		
		/**
		 * Builds a ShipmentState from a raw shipment array returned by the MyParcel API.
		 * Used by exchange() and the webhook controller.
		 * @param array $shipment The entry from data.shipments[] in the MyParcel response
		 * @return ShipmentState
		 */
		public function buildStateFromShipment(array $shipment): ShipmentState {
			$statusCode = $shipment['status'] ?? 'unknown';
			$internalState = (string)$statusCode;
			$status = self::STATUS_MAP[strtolower((string)$statusCode)] ?? ShipmentStatus::Unknown;
			$barcode = $shipment['barcode'] ?? null;
			$carrierId = $shipment['carrier_id'] ?? null;
			
			$trackingUrl = null;
			
			if ($barcode !== null) {
				$trackingUrl = $this->buildTrackingUrl(
					$barcode,
					$shipment['recipient']['postal_code'] ?? '',
					$carrierId
				);
			}
			
			return new ShipmentState(
				provider: self::DRIVER_NAME,
				parcelId: (string)($shipment['id'] ?? ''),
				reference: $shipment['reference_identifier'] ?? '',
				state: $status,
				trackingCode: $barcode,
				trackingUrl: $trackingUrl,
				statusMessage: null,
				internalState: $internalState,
				metadata: array_filter([
					'carrierId'   => $carrierId,
					'carrierName' => $this->carrierName($carrierId),
					'postalCode'  => $shipment['recipient']['postal_code'] ?? null,
					'weightGrams' => $shipment['physical_properties']['weight'] ?? null,
				], fn($v) => $v !== null),
			);
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
		 * @return array Keys: 'delivery', 'pickup'
		 */
		private function fetchDeliveryData(string $shippingModule, ?ShipmentAddress $address): array {
			if ($address === null) {
				return [];
			}
			
			$carrierInfo = self::MODULE_CARRIER_MAP[$shippingModule] ?? null;
			
			if ($carrierInfo === null) {
				return [];
			}
			
			$result = $this->getGateway()->getDeliveryOptions(
				$carrierInfo['carrierId'],
				$address->postalCode,
				$address->houseNumber,
				$address->city,
				$address->country
			);
			
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			return $result['response']['data'] ?? [];
		}
		
		/**
		 * Builds the recipient block for the MyParcel shipment payload.
		 * @param ShipmentAddress $address
		 * @return array
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
