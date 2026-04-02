<?php
	
	namespace Quellabs\Shipments\MyParcel;
	
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentCancellationException;
	use Quellabs\Shipments\Contracts\ShipmentCreationException;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
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
		 * MyParcel uses integer carrier IDs in the API payload, not names.
		 * The 'be' modules route to the Belgian sendmyparcel.be endpoint.
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
		 *
		 * @see https://developer.myparcel.nl/api-reference/06.track-trace.html
		 */
		private const STATUS_MAP = [
			// Pre-transit
			'registered'                        => ShipmentStatus::Created,
			'handed_to_carrier'                 => ShipmentStatus::ReadyToSend,
			// In transit
			'sorting'                           => ShipmentStatus::InTransit,
			'in_transit'                        => ShipmentStatus::InTransit,
			'transit'                           => ShipmentStatus::InTransit,
			'customs'                           => ShipmentStatus::InTransit,
			// Out for delivery
			'out_for_delivery'                  => ShipmentStatus::OutForDelivery,
			// Delivered
			'delivered'                         => ShipmentStatus::Delivered,
			'delivered_at_neighbor'             => ShipmentStatus::Delivered,
			'delivered_at_mailbox'              => ShipmentStatus::Delivered,
			// Pickup
			'available_for_pickup'              => ShipmentStatus::AwaitingPickup,
			'available_for_pickup_postnl'       => ShipmentStatus::AwaitingPickup,
			// Delivery issues
			'delivery_failed'                   => ShipmentStatus::DeliveryFailed,
			'not_delivered'                     => ShipmentStatus::DeliveryFailed,
			'refused_by_recipient'              => ShipmentStatus::DeliveryFailed,
			// Returns
			'return_to_sender'                  => ShipmentStatus::ReturnedToSender,
			'return_shipment_handed_to_carrier' => ShipmentStatus::ReturnedToSender,
			// Destroyed / lost
			'destroyed'                         => ShipmentStatus::Unknown,
			'lost'                              => ShipmentStatus::Unknown,
			// Explicitly unknown
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
				'region'         => 'nl',   // 'nl' → api.myparcel.nl, 'be' → api.sendmyparcel.be
				'mode'           => 'live', // 'live' | 'test'
				'sender_address' => [],
				'package_type'   => 1,      // 1=Package, 2=Mailbox, 3=Letter, 4=Digital stamp
			];
		}
		
		/**
		 * Creates a parcel via MyParcel and returns a structured result.
		 *
		 * MyParcel's creation response does NOT include the barcode or label URL inline.
		 * It only returns the internal shipment ID in data.ids[0].id.
		 * A separate call to getLabel() is required to obtain the label PDF URL.
		 * The barcode (tracking code) is assigned asynchronously by the carrier and must
		 * be fetched later via exchange() / tracktraces.
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
					'delivery_type'     => 2, // 2=Standard. Caller overrides via extraData if needed.
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
			
			// Merge provider-specific extra data supplied by the caller
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
			
			// MyParcel returns an array of IDs, one per shipment submitted
			$parcelId = (string)($result['response']['data']['ids'][0]['id'] ?? '');
			
			if ($parcelId === '') {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_id',
					'MyParcel did not return a shipment ID in the creation response'
				);
			}
			
			// Fetch the label PDF URL immediately if the caller requested it.
			// This is a second API call because MyParcel does not embed the label in the creation response.
			$labelUrl = null;
			
			if ($request->requestLabel) {
				$labelResult = $this->getGateway()->getLabel($parcelId);
				
				if ($labelResult['request']['result'] === 1) {
					$labelUrl = $labelResult['response']['data']['pdfs']['url'] ?? null;
				}
			}
			
			// Tracking code is NOT available at creation time — MyParcel assigns it asynchronously.
			// Use exchange() after a few minutes, or rely on the webhook, to obtain it.
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: $parcelId,
				reference: $request->reference,
				trackingCode: null,
				trackingUrl: null,
				labelUrl: $labelUrl,
				carrierName: $this->carrierName($carrierInfo['carrierId']),
				rawResponse: $result['response'],
			);
		}
		
		/**
		 * MyParcel does not provide a cancellation endpoint in API v1.1.
		 *
		 * Parcels can only be deleted from within the MyParcel panel, and only before
		 * the label has been scanned by the carrier. Throwing here makes the limitation
		 * explicit rather than silently failing or returning a misleading success result.
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
		 * We use the shipments endpoint (by ID) rather than tracktraces (by barcode) because
		 * the barcode may not yet be assigned immediately after creation, whereas the internal
		 * ID is always available from ShipmentResult::$parcelId.
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
		 * Returns delivery options (timeframes and pickup points) for the given module.
		 *
		 * MyParcel delivery options are computed per recipient address, so $address is
		 * required for meaningful results. Returns an empty array if $address is null.
		 *
		 * The returned array contains:
		 *   'delivery' — available home delivery timeframes
		 *   'pickup'   — nearby pickup points with their timeframes
		 *
		 * Pass additional query parameters (cutoff_time, dropoff_delay, deliverydays_window,
		 * etc.) via ShipmentRequest::$extraData or call the gateway directly.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return array
		 */
		public function getShippingOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
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
			
			// Build the public tracking URL from the barcode and postal code when available.
			// MyParcel does not return a tracking URL field directly; it must be constructed.
			$trackingUrl = null;
			
			if ($barcode !== null) {
				$postalCode = $shipment['recipient']['postal_code'] ?? '';
				$trackingUrl = $this->buildTrackingUrl($barcode, $postalCode, $carrierId);
			}
			
			return new ShipmentState(
				provider: self::DRIVER_NAME,
				parcelId: (string)($shipment['id'] ?? ''),
				reference: $shipment['reference_identifier'] ?? '',
				state: $status,
				trackingCode: $barcode,
				trackingUrl: $trackingUrl,
				statusMessage: null, // MyParcel does not return a human status label in the shipments endpoint
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
		 * Builds the recipient block for the MyParcel shipment payload.
		 * MyParcel expects street and house number as separate fields.
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
		 * Constructs a public track-and-trace URL from a barcode.
		 * MyParcel does not return a tracking URL field; it must be built from the barcode.
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