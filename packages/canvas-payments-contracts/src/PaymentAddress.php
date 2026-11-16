<?php
	
	namespace Quellabs\Payments\Contracts;
	
	final class PaymentAddress {

		/** Street name */
		public string $street;

		/** House number (e.g. 123, 12A) */
		public string $houseNumber;

		/** Postal code — required if the country has a postal code system */
		public string $postalCode;

		/** City */
		public string $city;

		/** ISO 3166-1 alpha-2 country code (NL, DE, BE, ...) */
		public string $country;

		/** House number suffix (e.g. bis, III, apartment letter) */
		public ?string $houseNumberSuffix;

		/** Title, e.g. Mr. or Mrs. */
		public ?string $title;

		/** First name — required for billie, in3, klarna, riverty */
		public ?string $givenName;

		/** Surname — required for billie, in3, klarna, riverty */
		public ?string $familyName;

		/** Organization name — required for billie */
		public ?string $organizationName;

		/** Region / province (e.g. Noord-Holland) */
		public ?string $region;

		/** Email address — required for billie, in3, klarna, riverty; also triggers auto-sent instructions for banktransfer */
		public ?string $email;

		/** Phone number in E.164 format (e.g. +31208202070) */
		public ?string $phone;

		/**
		 * @param string      $street              Street name
		 * @param string      $houseNumber         House number (e.g. 123, 12A)
		 * @param string      $postalCode          Postal code — required if the country has a postal code system
		 * @param string      $city                City
		 * @param string      $country             ISO 3166-1 alpha-2 country code (NL, DE, BE, ...)
		 * @param string|null $houseNumberSuffix   House number suffix (e.g. bis, III, apartment letter)
		 * @param string|null $title               Title, e.g. Mr. or Mrs.
		 * @param string|null $givenName           First name — required for billie, in3, klarna, riverty
		 * @param string|null $familyName          Surname — required for billie, in3, klarna, riverty
		 * @param string|null $organizationName    Organization name — required for billie
		 * @param string|null $region              Region / province (e.g. Noord-Holland)
		 * @param string|null $email               Email address — required for billie, in3, klarna, riverty;
		 *                                         also triggers auto-sent instructions for banktransfer
		 * @param string|null $phone               Phone number in E.164 format (e.g. +31208202070)
		 */
		public function __construct(
			string  $street,
			string  $houseNumber,
			string  $postalCode,
			string  $city,
			string  $country,
			?string $houseNumberSuffix = null,
			?string $title = null,
			?string $givenName = null,
			?string $familyName = null,
			?string $organizationName = null,
			?string $region = null,
			?string $email = null,
			?string $phone = null,
		) {
			$this->street = $street;
			$this->houseNumber = $houseNumber;
			$this->postalCode = $postalCode;
			$this->city = $city;
			$this->country = $country;
			$this->houseNumberSuffix = $houseNumberSuffix;
			$this->title = $title;
			$this->givenName = $givenName;
			$this->familyName = $familyName;
			$this->organizationName = $organizationName;
			$this->region = $region;
			$this->email = $email;
			$this->phone = $phone;
		}
	}