<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Passed to ShipmentProviderInterface::cancel() to cancel a shipment.
	 */
	class CancelRequest {
		
		public function __construct(
			/**
			 * The shipping module that was used when the parcel was created.
			 * Used by ShipmentRouter to dispatch to the correct provider.
			 */
			public readonly string $shippingModule,
			
			/**
			 * Provider-assigned parcel ID from ShipmentResult::$parcelId.
			 */
			public readonly string $parcelId,
			
			/**
			 * Your own reference (order number etc.) for logging and signal correlation.
			 */
			public readonly string $reference,
		) {
		}
	}
