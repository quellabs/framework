<?php
	
	namespace Quellabs\Payments\Contracts;
	
	final class PaymentAddress {
		
		/**
		 * @param string $streetAndNumber Street name and house number
		 * @param string $postalCode Postal code — required if the country has a postal code system
		 * @param string $city City
		 * @param string $country ISO 3166-1 alpha-2 country code (NL, DE, BE, ...)
		 * @param string|null $title Title, e.g. Mr. or Mrs.
		 * @param string|null $givenName First name — required for billie, in3, klarna, riverty
		 * @param string|null $familyName Surname — required for billie, in3, klarna, riverty
		 * @param string|null $organizationName Organization name — required for billie
		 * @param string|null $streetAdditional Additional address line (apartment, floor, ...)
		 * @param string|null $region Region / province (e.g. Noord-Holland)
		 * @param string|null $email Email address — required for billie, in3, klarna, riverty;
		 *                                       also triggers auto-sent instructions for banktransfer
		 * @param string|null $phone Phone number in E.164 format (e.g. +31208202070)
		 */
		public function __construct(
			public string  $streetAndNumber,
			public string  $postalCode,
			public string  $city,
			public string  $country,
			public ?string $title = null,
			public ?string $givenName = null,
			public ?string $familyName = null,
			public ?string $organizationName = null,
			public ?string $streetAdditional = null,
			public ?string $region = null,
			public ?string $email = null,
			public ?string $phone = null,
		) {
		}
	}