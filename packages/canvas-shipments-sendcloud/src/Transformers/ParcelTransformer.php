<?php
	
	namespace Quellabs\Shipments\SendCloud\Transformers;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	
	final class ParcelTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Driver name — used to populate provider fields on result objects.
		 */
		private string $driverName;
		
		/**
		 * Maps SendCloud parcel status IDs to our normalised ShipmentStatus values.
		 * SendCloud status IDs are integers; partial mapping is intentional — unmapped
		 * IDs fall through to ShipmentStatus::Unknown.
		 *
		 * @see https://docs.sendcloud.com/api/v2/#parcel-statuses
		 */
		private const array STATUS_MAP = [
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
		
		public function __construct(string $driverName) {
			$this->driverName = $driverName;
		}
		
		/**
		 * Builds the SendCloud parcel payload from a ShipmentRequest.
		 *
		 * $senderAddress is merged last and cannot be overridden by extraData.
		 * Keys in $senderAddress must match the SendCloud parcel field names exactly.
		 *
		 * @param ShipmentRequest $request
		 * @param array<string, mixed> $senderAddress Already-validated sender address from config
		 * @return array<string, mixed>
		 */
		public function fromRequest(ShipmentRequest $request, array $senderAddress): array {
			$address = $request->deliveryAddress;
			
			$parcel = array_filter([
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
			], fn($v) => $v !== null && $v !== '');
			
			// Merge extra data — protected keys (recipient address fields) are excluded
			if (!empty($request->extraData)) {
				$protectedKeys = ['name', 'company_name', 'address', 'house_number', 'city', 'postal_code', 'country', 'email', 'telephone'];
				$parcel = array_merge($parcel, array_diff_key($request->extraData, array_flip($protectedKeys)));
			}
			
			// Sender address is always applied last so it cannot be overridden by extraData
			if (!empty($senderAddress)) {
				$parcel = array_merge($parcel, $senderAddress);
			}
			
			return ['parcel' => $parcel];
		}
		
		/**
		 * Maps a raw SendCloud parcel array to a ShipmentResult.
		 * @param array<string, mixed> $parcel The 'parcel' key from the SendCloud API response
		 * @param ShipmentRequest $request The originating request, used to carry reference through
		 * @return ShipmentResult
		 */
		public function toShipmentResult(array $parcel, ShipmentRequest $request): ShipmentResult {
			return new ShipmentResult(
				provider: $this->driverName,
				parcelId: $this->normalizeString($parcel['id'] ?? ''),
				reference: $request->reference,
				trackingCode: $this->arrayGetString($parcel, 'tracking_number'),
				trackingUrl: $this->arrayGetString($parcel, 'tracking_url'),
				carrierName: $this->arrayGetString($parcel, 'carrier.name'),
				rawResponse: $parcel,
			);
		}
		
		/**
		 * Maps a raw SendCloud parcel array to a ShipmentState.
		 * Used by both exchange() and the webhook handler.
		 * @param array<string, mixed> $parcel The 'parcel' key from the SendCloud API response
		 * @return ShipmentState
		 */
		public function toShipmentState(array $parcel): ShipmentState {
			$status = is_array($parcel['status'] ?? null) ? $parcel['status'] : [];
			$statusId = $this->toInt($status['id'] ?? null, 92);
			$statusLabel = isset($status['message']) && is_string($status['message']) ? $status['message'] : null;
			$internalId = $this->normalizeString($status['id'] ?? 92);
			$internalMsg = $statusLabel ?? 'unknown';
			$internalState = $internalId . ':' . $internalMsg;
			$mappedStatus = self::STATUS_MAP[$statusId] ?? ShipmentStatus::Unknown;
			
			return new ShipmentState(
				provider: $this->driverName,
				parcelId: $this->normalizeString($parcel['id'] ?? ''),
				reference: $this->normalizeString($parcel['order_number'] ?? ''),
				state: $mappedStatus,
				trackingCode: $this->arrayGetString($parcel, 'tracking_number'),
				trackingUrl: $this->arrayGetString($parcel, 'tracking_url'),
				statusMessage: $statusLabel,
				internalState: $internalState,
				metadata: array_filter([
					'carrierId'      => $this->arrayGet($parcel, 'carrier.id'),
					'carrierName'    => $this->arrayGetString($parcel, 'carrier.name'),
					'cachedLabelUrl' => $this->arrayGetString($parcel, 'label.label_printer'),
					'servicePointId' => $parcel['to_service_point'] ?? null,
					'weightKg'       => $parcel['weight'] ?? null,
				], fn($v) => $v !== null),
			);
		}
	}