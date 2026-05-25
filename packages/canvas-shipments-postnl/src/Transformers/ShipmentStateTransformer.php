<?php
	
	namespace Quellabs\Shipments\PostNL\Transformers;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	use Quellabs\Shipments\PostNL\Driver;
	
	class ShipmentStateTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Maps PostNL status codes to normalised ShipmentStatus values.
		 *
		 * PostNL returns a numeric status code and a description via the Status API.
		 * Phase codes indicate the broad lifecycle stage; status codes refine it.
		 *
		 * Phase codes:
		 *   1 = Collected
		 *   2 = Sorting
		 *   3 = Distribution
		 *   4 = Delivered
		 *   99 = Return to sender
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/status/status-by-barcode
		 */
		private const array STATUS_MAP = [
			// Phase 1 — accepted by PostNL
			1  => ShipmentStatus::ReadyToSend,
			
			// Phase 2 — processing at sorting centre
			2  => ShipmentStatus::InTransit,
			
			// Phase 3 — in distribution network
			3  => ShipmentStatus::InTransit,
			
			// Phase 4 — final delivery outcomes
			4  => ShipmentStatus::Delivered,
			11 => ShipmentStatus::DeliveryFailed,
			12 => ShipmentStatus::AwaitingPickup,
			13 => ShipmentStatus::Delivered,       // Delivered at neighbour
			14 => ShipmentStatus::Delivered,       // Delivered in mailbox
			
			// Phase 99 — return
			99 => ShipmentStatus::ReturnedToSender,
		];
		
		/**
		 * Maps a successful PostNL status response to a ShipmentState.
		 *
		 * Expects $response to be $result['response'] from PostNLGateway::getStatus(),
		 * after the caller has already verified $result['request']['result'] !== 0.
		 *
		 * PostNL returns status events ordered newest-first; the first entry is current state.
		 * StatusCode takes precedence for fine-grained mapping; falls back to PhaseCode.
		 *
		 * @param array<string, mixed> $response Raw PostNL status response body.
		 * @param string $parcelId The PostNL barcode used to query the API.
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function transform(array $response, string $parcelId): ShipmentState {
			// Fetch the shipment data
			$shipmentData = $this->arrayGetArray($response, 'Shipment');
			
			// If that failed, throw an error
			if ($shipmentData === null) {
				throw new ShipmentExchangeException(
					Driver::DRIVER_NAME,
					'not_found',
					"No shipment data returned for barcode {$parcelId}"
				);
			}
			
			// PostNL returns status events ordered newest-first; the first entry is current state
			$events = $this->arrayGetArray($shipmentData, 'Events');
			$currentEvent = (is_array($events[0] ?? null)) ? $events[0] : [];
			$phaseCode = $this->toInt($currentEvent['PhaseCode'] ?? null);
			$statusCode = $this->toInt($currentEvent['StatusCode'] ?? null);
			$statusDescription = $this->arrayGetString($currentEvent, 'Description');
			
			// StatusCode takes precedence for fine-grained mapping; fall back to PhaseCode
			$status = self::STATUS_MAP[$statusCode] ?? self::STATUS_MAP[$phaseCode] ?? ShipmentStatus::Unknown;
			$internalState = $phaseCode . '.' . $statusCode;
			$barcode = $this->arrayGetString($shipmentData, 'Barcode') ?? '';
			$addresses = $this->arrayGetArray($shipmentData, 'Addresses');
			$firstAddress = (is_array($addresses[0] ?? null)) ? $addresses[0] : [];
			$postalCode = $this->arrayGetString($firstAddress, 'Zipcode') ?? '';
			$reference = $this->arrayGetString($shipmentData, 'Reference') ?? '';
			
			return new ShipmentState(
				provider: Driver::DRIVER_NAME,
				parcelId: $barcode,
				reference: $reference,
				state: $status,
				trackingCode: $barcode,
				trackingUrl: PostNLUrlBuilder::trackingUrl($barcode, $postalCode),
				statusMessage: $statusDescription,
				internalState: $internalState,
				metadata: array_filter([
					'phaseCode'   => $phaseCode ?: null,
					'statusCode'  => $statusCode ?: null,
					'postalCode'  => $postalCode ?: null,
					'productCode' => $this->arrayGetString($shipmentData, 'ProductCode'),
				], fn($v) => $v !== null),
			);
		}
	}