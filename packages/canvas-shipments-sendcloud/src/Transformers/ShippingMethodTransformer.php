<?php
	
	namespace Quellabs\Shipments\SendCloud\Transformers;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\DeliveryOption;
	use Quellabs\Shipments\Contracts\PickupOption;
	
	final class ShippingMethodTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Maps a raw SendCloud shipping method array to a DeliveryOption.
		 * @param array<string, mixed> $method
		 * @return DeliveryOption
		 */
		public function toDeliveryOption(array $method): DeliveryOption {
			return new DeliveryOption(
				methodId: $this->normalizeString($method['id'] ?? ''),
				label: $this->normalizeString($method['name'] ?? ''),
				carrierName: $this->normalizeString($method['carrier'] ?? ''),
				metadata: array_filter([
					'minWeightGrams' => isset($method['min_weight']) ? (int)round($this->toFloat($method['min_weight']) * 1000) : null,
					'maxWeightGrams' => isset($method['max_weight']) ? (int)round($this->toFloat($method['max_weight']) * 1000) : null,
					'price'          => $method['price'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Maps a raw SendCloud service point array to a PickupOption.
		 * Malformed (non-array) entries produce a blank PickupOption so array_map
		 * callers never receive a sparse result — filter those out afterwards if needed.
		 * @param mixed $point
		 * @return PickupOption
		 */
		public function toPickupOption(mixed $point): PickupOption {
			// Service point entries are always arrays; return a blank entry for malformed ones
			if (!is_array($point)) {
				return new PickupOption(
					locationCode: '', name: '', street: '', houseNumber: '',
					postalCode: '', city: '', country: '', carrierName: '',
					latitude: null, longitude: null, distanceMetres: null, metadata: [],
				);
			}
			
			return new PickupOption(
				locationCode: $this->normalizeString($point['id'] ?? ''),
				name: $this->normalizeString($point['name'] ?? ''),
				street: $this->normalizeString($point['street'] ?? ''),
				houseNumber: $this->normalizeString($point['house_number'] ?? ''),
				postalCode: $this->normalizeString($point['postal_code'] ?? ''),
				city: $this->normalizeString($point['city'] ?? ''),
				country: $this->normalizeString($point['country'] ?? ''),
				carrierName: $this->normalizeString($point['carrier'] ?? ''),
				latitude: isset($point['latitude']) ? $this->toFloat($point['latitude']) : null,
				longitude: isset($point['longitude']) ? $this->toFloat($point['longitude']) : null,
				distanceMetres: isset($point['distance']) ? $this->toInt($point['distance']) : null,
				metadata: array_filter([
					'openingHours' => $point['opening_hours'] ?? null,
					'extraInfo'    => $point['extra_info'] ?? null,
					'phone'        => $point['phone_number'] ?? null,
				], fn($v) => $v !== null),
			);
		}
	}