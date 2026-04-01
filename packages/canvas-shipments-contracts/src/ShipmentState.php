<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Represents the current state of a shipment at a point in time.
	 * Emitted via Signal on every webhook event and every exchange() call.
	 *
	 * Consumers (e.g. order management) subscribe to the signal and use this object
	 * to update the shipment record in their own data store.
	 */
	class ShipmentState {
		
		public function __construct(
			/**
			 * Driver name that produced this state (e.g. 'sendcloud').
			 * Use this as the $driver argument when calling ShipmentRouter::exchange() later.
			 */
			public readonly string         $provider,
			
			/**
			 * Provider-assigned parcel ID — the stable identifier for this shipment.
			 */
			public readonly string         $parcelId,
			
			/**
			 * Your own reference passed in ShipmentRequest::$reference.
			 * Use this to look up the corresponding order in your system.
			 */
			public readonly string         $reference,
			
			/**
			 * Current status of the shipment.
			 */
			public readonly ShipmentStatus $state,
			
			/**
			 * Carrier-assigned tracking code (may be populated later than parcel creation).
			 */
			public readonly ?string        $trackingCode,
			
			/**
			 * Public tracking URL. Include in customer-facing emails or order detail pages.
			 */
			public readonly ?string        $trackingUrl,
			
			/**
			 * Human-readable status message from the provider (e.g. "Delivered at front door").
			 */
			public readonly ?string        $statusMessage,
			
			/**
			 * Provider-specific raw status code or event name, preserved for logging.
			 * Equivalent to PaymentState::$internalState.
			 */
			public readonly string         $internalState,
			
			/**
			 * Additional provider-specific data not covered by the typed fields above.
			 * Example keys: 'carrierId', 'servicePointId', 'weight', 'labelUrl'.
			 */
			public readonly array          $metadata = [],
		) {
		}
	}
