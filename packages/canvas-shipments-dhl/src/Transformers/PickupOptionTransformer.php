<?php
	
	namespace Quellabs\Shipments\DHL\Transformers;
	
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	
	/**
	 * Transforms the raw MyParcel 'pickup' array (from the delivery_options endpoint)
	 * into a list of PickupOption objects.
	 *
	 * Does not need MyParcelHelpers — all data comes directly from the API response
	 * with no carrier-specific derived values.
	 */
	class PickupOptionTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Transforms a single pickup location into a PickupOption.
		 * @param array<mixed, mixed> $shop One entry from the pickup array.
		 * @param ShipmentAddress $address
		 * @return PickupOption
		 */
		public function transform(array $shop, ShipmentAddress $address): PickupOption {
			// Extract address
			$addr = isset($shop['address']) && is_array($shop['address']) ? $shop['address'] : [];
			
			// Extract and coerce all fields before construction to keep the call site readable
			$locationCode = $this->normalizeString($shop['id'] ?? null);
			$name = $this->normalizeString($shop['name'] ?? null);
			$street = $this->normalizeString($addr['street'] ?? null);
			$houseNumber = $this->normalizeString($addr['number'] ?? null);
			$postalCode = $this->normalizeString($addr['postalCode'] ?? $addr['zipCode'] ?? null);
			$city = $this->normalizeString($addr['city'] ?? null);
			$country = $this->normalizeString($addr['countryCode'] ?? null) ?: $address->country;
			$geoLocation = isset($shop['geoLocation']) && is_array($shop['geoLocation']) ? $shop['geoLocation'] : [];
			$shopType = $this->normalizeString($shop['shopType'] ?? null) ?: null;
			$openingTimes = isset($shop['openingTimes']) && is_array($shop['openingTimes']) ? $shop['openingTimes'] : null;
			
			// Add address to response
			return new PickupOption(
				locationCode: $locationCode,
				name: $name,
				street: $street,
				houseNumber: $houseNumber,
				postalCode: $postalCode,
				city: $city,
				country: $country,
				carrierName: 'DHL',
				latitude: is_numeric($geoLocation['latitude'] ?? null) ? (float)$geoLocation['latitude'] : null,
				longitude: is_numeric($geoLocation['longitude'] ?? null) ? (float)$geoLocation['longitude'] : null,
				distanceMetres: is_numeric($shop['distance'] ?? null) ? (int)$shop['distance'] : null,
				metadata: array_filter([
					'shopType'     => $shopType,
					'openingTimes' => $openingTimes,
				], fn($v) => $v !== null),
			);
		}
	}