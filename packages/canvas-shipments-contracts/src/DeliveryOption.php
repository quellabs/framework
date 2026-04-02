<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Represents a single home delivery option returned by getDeliveryOptions().
	 *
	 * For providers like SendCloud, this maps to a shipping method (id = method id).
	 * For providers like MyParcel, this maps to a delivery timeslot (id = date+slot key).
	 *
	 * Use $methodId when constructing a ShipmentRequest.
	 */
	class DeliveryOption {
		
		public function __construct(
			/**
			 * Stable identifier to pass as ShipmentRequest::$methodId.
			 * For SendCloud: the integer shipping method ID.
			 * For MyParcel: a composite key encoding date and timeslot (e.g. '2026-04-05:09:00:12:00').
			 */
			public readonly string              $methodId,
			
			/**
			 * Human-readable label suitable for display in a checkout UI.
			 * Examples: 'PostNL Standard', 'Tomorrow 09:00–12:00', 'Evening delivery'
			 */
			public readonly string              $label,
			
			/**
			 * Carrier name (e.g. 'PostNL', 'DHL').
			 */
			public readonly string              $carrierName,
			
			/**
			 * Earliest delivery date, when known. Null for methods without a specific date.
			 */
			public readonly ?\DateTimeImmutable $deliveryDate = null,
			
			/**
			 * Start of the delivery window (e.g. 09:00). Null when no window is specified.
			 */
			public readonly ?string             $windowStart = null,
			
			/**
			 * End of the delivery window (e.g. 12:00). Null when no window is specified.
			 */
			public readonly ?string             $windowEnd = null,
			
			/**
			 * Additional provider-specific data not covered by the typed fields above.
			 * Examples: min_weight, max_weight, price, price_comment.
			 */
			public readonly array               $metadata = [],
		) {
		}
	}
