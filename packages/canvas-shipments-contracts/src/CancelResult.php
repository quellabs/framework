<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Returned by ShipmentProviderInterface::cancel() after a successful cancellation.
	 */
	class CancelResult {
		
		public function __construct(
			/** Driver that processed the cancellation */
			public readonly string  $provider,
			
			/** Provider-assigned parcel ID that was cancelled */
			public readonly string  $parcelId,
			
			/** Your own reference echoed back */
			public readonly string  $reference,
			
			/** Whether the cancellation was accepted by the provider */
			public readonly bool    $accepted,
			
			/**
			 * Optional message from the provider explaining the outcome.
			 * Populated when accepted is false (e.g. "Parcel already in transit").
			 */
			public readonly ?string $message = null,
		) {
		}
	}
