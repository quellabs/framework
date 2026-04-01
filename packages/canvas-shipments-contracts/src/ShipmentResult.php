<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Returned by ShipmentProviderInterface::ship() after successful parcel creation.
	 * Persist all fields — parcelId and trackingCode are needed for webhook correlation
	 * and cancellation; labelUrl gives the label on demand without a second API call.
	 */
	class ShipmentResult {
		
		public function __construct(
			/**
			 * Driver name (e.g. 'sendcloud').
			 * Stored alongside the parcel so ShipmentRouter::exchange() can be called later
			 * without knowing which provider was used at the time of shipping.
			 */
			public readonly string  $provider,
			
			/**
			 * Provider-assigned parcel ID.
			 * This is the stable identifier used in exchange(), cancel(), and webhook events.
			 */
			public readonly string  $parcelId,
			
			/**
			 * The caller's own reference passed in ShipmentRequest::$reference.
			 * Echoed back here so consumers don't need to correlate it themselves.
			 */
			public readonly string  $reference,
			
			/**
			 * Carrier-assigned tracking code. May be null immediately after creation if the
			 * provider assigns it asynchronously (e.g. after label generation).
			 */
			public readonly ?string $trackingCode,
			
			/**
			 * Public tracking URL ready to embed in confirmation emails.
			 * May be null if the provider does not return one at creation time.
			 */
			public readonly ?string $trackingUrl,
			
			/**
			 * Direct URL to the shipping label PDF/ZPL.
			 * Null if requestLabel was false or if the provider assigns labels asynchronously.
			 */
			public readonly ?string $labelUrl,
			
			/**
			 * Human-readable carrier name (e.g. 'PostNL', 'DHL').
			 * Derived from the provider's response — useful for display without a second lookup.
			 */
			public readonly ?string $carrierName = null,
			
			/**
			 * Raw provider response, preserved for debugging and for fields not covered here.
			 */
			public readonly array   $rawResponse = [],
		) {
		}
	}
