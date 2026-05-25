<?php
	
	namespace Quellabs\Shipments\MyParcel\Transformers;
	
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	use Quellabs\Shipments\MyParcel\MyParcelHelpers;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	
	/**
	 * Transforms the raw MyParcel shipment record (from the shipments endpoint)
	 * into a normalised ShipmentState.
	 *
	 * The shipments endpoint is used rather than tracktraces because the barcode
	 * may not yet be assigned immediately after creation.
	 */
	class ShipmentStateTransformer {
		
		use GatewayHelpers;
		use MyParcelHelpers;
		
		/**
		 * Maps MyParcel track-trace status codes to normalised ShipmentStatus values.
		 * Status codes are lowercase strings per the MyParcel API docs (v1.1).
		 * @see https://developer.myparcel.nl/api-reference/06.track-trace.html
		 */
		private const array STATUS_MAP = [
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
		
		/** @var string The driver constant, passed in to avoid coupling to Driver. */
		private readonly string $driverName;
		
		/**
		 * ShipmentStateTransformer constructor
		 * @param string $driverName
		 */
		public function __construct(string $driverName) {
			$this->driverName = $driverName;
		}
		
		/**
		 * Builds a ShipmentState from a single shipment record.
		 * @param array<string, mixed> $shipment One entry from data.shipments in the API response.
		 * @return ShipmentState
		 */
		public function transform(array $shipment): ShipmentState {
			$rawCarrierId = $shipment['carrier_id'] ?? null;
			$carrierId = is_numeric($rawCarrierId) ? (int)$rawCarrierId : null;
			$parcelId = $this->normalizeString($shipment['id'] ?? '');
			$reference = $this->arrayGetString($shipment, 'reference_identifier') ?? '';
			$internalState = $this->arrayGetString($shipment, 'status') ?? 'unknown';
			$status = self::STATUS_MAP[strtolower($internalState)] ?? ShipmentStatus::Unknown;
			$barcode = $this->arrayGetString($shipment, 'barcode');
			$postalCode = $this->arrayGetString($shipment, 'recipient.postal_code') ?? '';
			$trackingUrl = $barcode !== null ? $this->buildTrackingUrl($barcode, $postalCode, $carrierId) : null;
			
			return new ShipmentState(
				provider: $this->driverName,
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
	}