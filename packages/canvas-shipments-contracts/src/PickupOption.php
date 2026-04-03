<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Represents a single pickup point returned by getPickupOptions().
	 *
	 * Use $locationCode when constructing a ShipmentRequest::$servicePointId.
	 */
	class PickupOption {
		
		public function __construct(
			/**
			 * Stable identifier to pass as ShipmentRequest::$servicePointId.
			 * For SendCloud: the service point ID.
			 * For MyParcel: the location_code.
			 */
			public readonly string $locationCode,
			
			/**
			 * Name of the pickup location (e.g. 'Albert Heijn Keizersgracht').
			 */
			public readonly string $name,
			
			/**
			 * Street address of the pickup point.
			 */
			public readonly string $street,
			
			/**
			 * House number of the pickup point.
			 */
			public readonly string $houseNumber,
			
			/**
			 * Postal code of the pickup point.
			 */
			public readonly string $postalCode,
			
			/**
			 * City of the pickup point.
			 */
			public readonly string $city,
			
			/**
			 * ISO 3166-1 alpha-2 country code of the pickup point.
			 */
			public readonly string $country,
			
			/**
			 * Carrier that operates this pickup point (e.g. 'PostNL', 'DHL').
			 */
			public readonly string $carrierName,
			
			/**
			 * Latitude coordinate, when provided by the provider.
			 */
			public readonly ?float $latitude = null,
			
			/**
			 * Longitude coordinate, when provided by the provider.
			 */
			public readonly ?float $longitude = null,
			
			/**
			 * Distance from the queried address in metres, when provided by the provider.
			 */
			public readonly ?int   $distanceMetres = null,
			
			/**
			 * Additional provider-specific data not covered by the typed fields above.
			 * Examples: opening_hours, retail_network_id, phone_number.
			 */
			public readonly array  $metadata = [],
		) {
		}
	}
