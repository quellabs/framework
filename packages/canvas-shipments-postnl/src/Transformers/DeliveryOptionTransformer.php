<?php
	
	namespace Quellabs\Shipments\PostNL\Transformers;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\DeliveryOption;
	
	class DeliveryOptionTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Maps a successful PostNL Timeframe API response to an array of DeliveryOption objects.
		 *
		 * Expects $response to be $result['response'] from PostNLGateway::getTimeframes(),
		 * after the caller has already verified $result['request']['result'] !== 0.
		 *
		 * The Timeframe API wraps its result in Timeframes.Timeframe[]. When the API
		 * returns a single day it may not wrap it in an array; this is normalised here.
		 * The same single-item wrapping caveat applies to slots within each day.
		 *
		 * The methodId encodes the delivery date, window, and option type so the caller
		 * can pass it back in ShipmentRequest::$methodId and the driver can reconstruct
		 * what product option code is needed at shipment creation time.
		 * Format: 'dd-mm-yyyy|HH:MM:SS|HH:MM:SS|OptionType'
		 * Example: '08-04-2026|08:00:00|12:00:00|Morning'
		 *
		 * @param array<string, mixed> $response Raw PostNL Timeframe response body.
		 * @param string $productCode Product code for the selected shipping module.
		 * @return DeliveryOption[]
		 */
		public function transform(array $response, string $productCode): array {
			// The Timeframe API wraps its result in Timeframes.Timeframe[]
			$days = $this->arrayGetArray($response, 'Timeframes.Timeframe') ?? [];
			
			// When the API returns a single day it may not wrap it in an array
			if (isset($days['Date'])) {
				$days = [$days];
			}
			
			// Transform timeframes
			$options = [];
			
			foreach ($days as $day) {
				if (!is_array($day)) {
					continue;
				}
				
				$dateStr = $this->arrayGetString($day, 'Date');
				
				if ($dateStr === null) {
					continue;
				}
				
				$date = \DateTimeImmutable::createFromFormat('d-m-Y', $dateStr) ?: null;
				
				// Slots are in TimeframeTimeFrame[], same single-item wrapping caveat applies
				$slots = $this->arrayGetArray($day, 'Timeframes.TimeframeTimeFrame') ?? [];
				
				if (isset($slots['From'])) {
					$slots = [$slots];
				}
				
				foreach ($slots as $slot) {
					if (!is_array($slot)) {
						continue;
					}
					
					$from = $this->arrayGetString($slot, 'From') ?? '';   // e.g. '08:00:00'
					$to = $this->arrayGetString($slot, 'To') ?? '';     // e.g. '12:00:00'
					
					// Options is either a string or { "string": "Morning" }
					$optionsField = $slot['Options'] ?? 'Daytime';
					
					if (is_array($optionsField)) {
						$optionType = ($this->arrayGetString($optionsField, 'string') ?? 'Daytime');
					} else {
						$optionType = (is_string($optionsField) ? $optionsField : 'Daytime');
					}
					
					// Build window
					$windowStart = substr($from, 0, 5); // '08:00:00' → '08:00'
					$windowEnd = substr($to, 0, 5);
					
					// Add DeliveryOption
					$options[] = new DeliveryOption(
						methodId: "{$dateStr}|{$from}|{$to}|{$optionType}",
						label: $this->buildDeliveryLabel($date, $windowStart, $windowEnd, $optionType),
						carrierName: 'PostNL',
						deliveryDate: $date,
						windowStart: $windowStart ?: null,
						windowEnd: $windowEnd ?: null,
						metadata: [
							'optionType'  => $optionType,
							'productCode' => $productCode,
						],
					);
				}
			}
			
			return $options;
		}
		
		/**
		 * Builds a human-readable label for a delivery timeframe slot.
		 * @param \DateTimeImmutable|null $date
		 * @param string $windowStart e.g. '08:00'
		 * @param string $windowEnd e.g. '12:00'
		 * @param string $optionType e.g. 'Morning', 'Evening', 'Daytime', 'Sunday'
		 * @return string
		 */
		private function buildDeliveryLabel(?\DateTimeImmutable $date, string $windowStart, string $windowEnd, string $optionType): string {
			$dateStr = $date ? $date->format('d-m-Y') : '';
			$window = ($windowStart !== '' && $windowEnd !== '') ? " {$windowStart}–{$windowEnd}" : '';
			
			return match ($optionType) {
				'Morning' => trim("{$dateStr} Morning{$window}"),
				'Evening' => trim("{$dateStr} Evening{$window}"),
				'Sunday' => trim("{$dateStr} Sunday{$window}"),
				default => trim("{$dateStr}{$window}"),
			};
		}
	}