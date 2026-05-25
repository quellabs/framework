<?php
	
	namespace Quellabs\Shipments\DPD\Transformers;
	
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	
	class DeliveryOptionTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Transforms the raw data dpd delivered to a PickupOption object
		 * @param array<string, mixed> $shop
		 * @param ShipmentAddress $address
		 * @return PickupOption
		 */
		function transform(array $shop, ShipmentAddress $address): PickupOption {
			$locationCode = $this->normalizeString($shop['parcelShopId'] ?? null);
			$name = is_string($shop['company'] ?? null) ? $shop['company'] : '';
			$street = is_string($shop['street'] ?? null) ? $shop['street'] : '';
			$houseNumber = $this->normalizeString($shop['houseNo'] ?? null);
			$postalCode = is_string($shop['zipCode'] ?? null) ? $shop['zipCode'] : '';
			$city = is_string($shop['city'] ?? null) ? $shop['city'] : '';
			$country = is_string($shop['country'] ?? null) ? $shop['country'] : $address->country;
			$latitude = isset($shop['latitude']) && is_numeric($shop['latitude']) ? (float)$shop['latitude'] : null;
			$longitude = isset($shop['longitude']) && is_numeric($shop['longitude']) ? (float)$shop['longitude'] : null;
			$distanceMetres = isset($shop['distance']) && is_numeric($shop['distance']) ? (int)round((float)$shop['distance'] * 1000) : null;
			
			return new PickupOption(
				locationCode: $locationCode,
				name: $name,
				street: $street,
				houseNumber: $houseNumber,
				postalCode: $postalCode,
				city: $city,
				country: $country,
				carrierName: 'DPD',
				latitude: $latitude,
				longitude: $longitude,
				distanceMetres: $distanceMetres,
				metadata: array_filter([
					'openingHours' => $shop['openingHours'] ?? null,
					'phone'        => $shop['phone'] ?? null,
					'email'        => $shop['email'] ?? null,
				], fn($v) => $v !== null && $v !== '')
			);
		}
	}