<?php
	
	namespace Quellabs\Shipments\MyParcel\Transformers;
	
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	
	/**
	 * Transforms the raw MyParcel 'pickup' array (from the delivery_options endpoint)
	 * into a list of PickupOption objects.
	 *
	 * Does not need MyParcelHelpers — all data comes directly from the API response
	 * with no carrier-specific derived values.
	 */
	class PickupOptionTransformer {
		
		use GatewayHelpers;
		
		/** @var string Human-readable carrier name (e.g. 'PostNL') */
		private readonly string $carrierName;
		
		/**
		 * PickupOptionTransformer constructor
		 * @param string $carrierName
		 */
		public function __construct(string $carrierName) {
			$this->carrierName = $carrierName;
		}
		
		/**
		 * Transforms the 'pickup' array from the delivery_options response.
		 * @param array<int, mixed> $locations The value of data.pickup in the API response.
		 * @return PickupOption[]
		 */
		public function transformAll(array $locations): array {
			$options = [];
			
			foreach ($locations as $location) {
				// Skip invalid locations
				if (!is_array($location)) {
					continue;
				}
				
				$options[] = $this->transformLocation($location);
			}
			
			return $options;
		}
		
		/**
		 * Transforms a single pickup location into a PickupOption.
		 * @param array<mixed, mixed> $location One entry from the pickup array.
		 * @return PickupOption
		 */
		private function transformLocation(array $location): PickupOption {
			$rawDistance = $this->arrayGet($location, 'distance');
			
			return new PickupOption(
				locationCode: $this->arrayGetString($location, 'location_code') ?? '',
				name: $this->arrayGetString($location, 'location') ?? '',
				street: $this->arrayGetString($location, 'street') ?? '',
				houseNumber: $this->arrayGetString($location, 'number') ?? '',
				postalCode: $this->arrayGetString($location, 'postal_code') ?? '',
				city: $this->arrayGetString($location, 'city') ?? '',
				country: $this->arrayGetString($location, 'cc') ?? '',
				carrierName: $this->carrierName,
				latitude: $this->toFloat($this->arrayGet($location, 'latitude'), 0.0) ?: null,
				longitude: $this->toFloat($this->arrayGet($location, 'longitude'), 0.0) ?: null,
				distanceMetres: $rawDistance !== null ? $this->toInt($rawDistance) : null,
				metadata: array_filter([
					'phone'           => $this->arrayGetString($location, 'phone_number'),
					'comment'         => $this->arrayGetString($location, 'comment'),
					'openingHours'    => $this->arrayGetArray($location, 'opening_hours'),
					'retailNetworkId' => $this->arrayGetString($location, 'retail_network_id'),
				], fn($value) => $value !== null),
			);
		}
	}