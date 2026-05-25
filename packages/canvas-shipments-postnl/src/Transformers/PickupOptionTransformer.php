<?php
	
	namespace Quellabs\Shipments\PostNL\Transformers;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\PickupOption;
	
	class PickupOptionTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Maps a successful PostNL Location API response to an array of PickupOption objects.
		 *
		 * Expects $response to be $result['response'] from PostNLGateway::getNearestLocations(),
		 * after the caller has already verified $result['request']['result'] !== 0.
		 *
		 * @param array<string, mixed> $response Raw PostNL Location response body.
		 * @return PickupOption[]
		 */
		public function transform(array $response): array {
			// Fetch location data
			$locations = $this->arrayGetArray($response, 'GetLocationsResult.ResponseLocation') ?? [];
			
			// Transform data to PickupOption objects
			$options = [];
			
			foreach ($locations as $location) {
				if (!is_array($location)) {
					continue;
				}
				
				$address_ = $this->arrayGetArray($location, 'Address') ?? [];
				
				$options[] = new PickupOption(
					locationCode: $this->arrayGetString($location, 'LocationCode') ?? '',
					name: $this->arrayGetString($location, 'Name') ?? '',
					street: $this->arrayGetString($address_, 'Street') ?? '',
					houseNumber: $this->arrayGetString($address_, 'HouseNr') ?? '',
					postalCode: $this->arrayGetString($address_, 'Zipcode') ?? '',
					city: $this->arrayGetString($address_, 'City') ?? '',
					country: $this->arrayGetString($address_, 'Countrycode') ?? '',
					carrierName: 'PostNL',
					latitude: $this->toFloat($location['Latitude'] ?? null) ?: null,
					longitude: $this->toFloat($location['Longitude'] ?? null) ?: null,
					distanceMetres: isset($location['Distance']) ? $this->toInt($location['Distance']) : null,
					metadata: array_filter([
						'retailNetworkId' => $location['RetailNetworkID'] ?? null,
						'partnerName'     => $location['PartnerName'] ?? null,
						'openingHours'    => $location['OpeningHours'] ?? null,
						'phoneNumber'     => $location['PhoneNumber'] ?? null,
						'deliveryOptions' => $location['DeliveryOptions'] ?? null,
					], fn($v) => $v !== null),
				);
			}
			
			return $options;
		}
	}