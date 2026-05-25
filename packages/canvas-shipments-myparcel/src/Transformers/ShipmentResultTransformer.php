<?php
	
	namespace Quellabs\Shipments\MyParcel\Transformers;
	
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\MyParcel\MyParcelHelpers;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	
	/**
	 * Transforms the raw MyParcel parcel-creation response into a ShipmentResult.
	 *
	 * Tracking code and URL are always null at creation time — MyParcel assigns
	 * the barcode asynchronously after the label is generated. Use exchange() or
	 * a webhook to obtain them later.
	 */
	class ShipmentResultTransformer {
		
		use GatewayHelpers;
		use MyParcelHelpers;
		
		/** @var string The driver constant, passed in to avoid coupling to Driver. */
		private readonly string $driverName;
		
		/** @var int The MyParcel carrier ID for this shipment. */
		private readonly int $carrierId;
		
		/** @var string  The caller's own reference from the ShipmentRequest. */
		private readonly string $reference;
		
		/**
		 * ShipmentResultTransformer constructor
		 * @param string $driverName
		 * @param int $carrierId
		 * @param string $reference
		 */
		public function __construct(string $driverName, int $carrierId, string $reference) {
			$this->reference = $reference;
			$this->carrierId = $carrierId;
			$this->driverName = $driverName;
		}
		
		/**
		 * Builds a ShipmentResult from the raw API response body.
		 * @param array<string, mixed> $response The 'response' key from the gateway result.
		 * @return ShipmentResult
		 */
		public function transform(array $response): ShipmentResult {
			$parcelId = $this->arrayGetString($response, 'data.ids.0.id') ?? '';
			
			return new ShipmentResult(
				provider: $this->driverName,
				parcelId: $parcelId,
				reference: $this->reference,
				trackingCode: null,
				trackingUrl: null,
				carrierName: $this->carrierName($this->carrierId),
				rawResponse: $response,
			);
		}
	}