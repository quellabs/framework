<?php
	
	namespace Quellabs\Shipments\MyParcel\Transformers;
	
	use Quellabs\Shipments\Contracts\DeliveryOption;
	use Quellabs\Shipments\MyParcel\MyParcelHelpers;
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	
	/**
	 * Transforms the raw MyParcel 'delivery' array (from the delivery_options endpoint)
	 * into a flat list of DeliveryOption objects.
	 *
	 * MyParcel groups timeslots by date — each date entry has a 'time' array of slots.
	 * This transformer flattens that structure: one DeliveryOption per slot.
	 */
	class DeliveryOptionTransformer {
		
		use GatewayHelpers;
		use MyParcelHelpers;
		
		/** @var string Human-readable carrier name (e.g. 'PostNL'). */
		private readonly string $carrierName;
		
		/**
		 * DeliveryOptionTransformer constructor
		 * @param string $carrierName
		 */
		public function __construct(string $carrierName) {
			$this->carrierName = $carrierName;
		}
		
		/**
		 * Transforms the 'delivery' array from the delivery_options response.
		 *
		 * @param array<int, mixed> $timeframes  The value of data.delivery in the API response.
		 * @return DeliveryOption[]
		 */
		public function transformAll(array $timeframes): array {
			$options = [];
			
			foreach ($timeframes as $timeframe) {
				// Skip invalid timeframes
				if (!is_array($timeframe)) {
					continue;
				}
				
				// Parse the date once per timeframe — all slots share it
				$rawDate = $this->arrayGetString($timeframe, 'date') ?? '';
				$date    = $rawDate !== '' ? (\DateTimeImmutable::createFromFormat('Y-m-d', $rawDate) ?: null) : null;
				
				foreach ($this->arrayGetArray($timeframe, 'time') ?? [] as $slot) {
					// Skip invalid slots
					if (!is_array($slot)) {
						continue;
					}
					
					$options[] = $this->transformSlot($rawDate, $date, $slot);
				}
			}
			
			return $options;
		}
		
		/**
		 * Transforms a single timeslot into a DeliveryOption.
		 *
		 * @param string                   $rawDate  ISO date string (e.g. '2026-04-05'), used in methodId.
		 * @param \DateTimeImmutable|null   $date     Parsed date, or null if unparseable.
		 * @param array<mixed, mixed>     $slot     One entry from the timeframe's 'time' array.
		 * @return DeliveryOption
		 */
		private function transformSlot(string $rawDate, ?\DateTimeImmutable $date, array $slot): DeliveryOption {
			// '09:00:00' → '09:00'
			$start = substr($this->arrayGetString($slot, 'start') ?? '', 0, 5);
			$end   = substr($this->arrayGetString($slot, 'end') ?? '', 0, 5);
			$type  = $this->arrayGetString($slot, 'price_comment') ?? 'standard';
			$label = $this->buildDeliveryLabel($date, $start, $end, $type);
			
			return new DeliveryOption(
				methodId:     $rawDate . ':' . $start . ':' . $end,
				label:        $label,
				carrierName:  $this->carrierName,
				deliveryDate: $date,
				windowStart:  $start ?: null,
				windowEnd:    $end ?: null,
				metadata:     array_filter([
					'type'  => $type,
					'price' => $slot['price'] ?? null,
				], fn($v) => $v !== null),
			);
		}
	}