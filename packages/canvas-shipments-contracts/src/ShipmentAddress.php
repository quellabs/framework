<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Represents a physical address used in shipment requests.
	 * Structured to cover both Dutch house-number conventions and generic international formats.
	 */
	class ShipmentAddress {
		
		public function __construct(
			/** @var string Recipient full name (used on label) */
			public readonly string  $name,
			
			/** @var string Street name without house number */
			public readonly string  $street,
			
			/** @var string House number (numeric part only for NL, full for international) */
			public readonly string  $houseNumber,
			
			/** @var string|null House number suffix/addition (e.g. 'A', 'bis') */
			public readonly ?string $houseNumberSuffix,
			
			/** @var string Postal / ZIP code */
			public readonly string  $postalCode,
			
			/** @var string City name */
			public readonly string  $city,
			
			/** @var string ISO 3166-1 alpha-2 country code */
			public readonly string  $country,
			
			/** @var string|null Contact email (used for tracking notifications) */
			public readonly ?string $email = null,
			
			/** @var string|null Contact phone number */
			public readonly ?string $phone = null,
			
			/** @var string|null Company name (shown on label) */
			public readonly ?string $company = null,
			
			/** @var string|null State or region (required for US, AU, CA etc.) */
			public readonly ?string $region = null,
		) {
		}
	}
