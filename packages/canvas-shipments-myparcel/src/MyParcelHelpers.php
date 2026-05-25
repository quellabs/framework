<?php
	
	namespace Quellabs\Shipments\MyParcel;
	
	trait MyParcelHelpers {
		
		/**
		 * Returns a human-readable carrier name from a MyParcel carrier ID.
		 * @param int|null $carrierId
		 * @return string|null
		 */
		private function carrierName(?int $carrierId): ?string {
			return match ($carrierId) {
				1 => 'PostNL',
				2 => 'bpost',
				3 => 'CheapCargo',
				4 => 'DHL',
				5 => 'DHL For You',
				8 => 'DPD',
				default => null,
			};
		}
		
		/**
		 * Constructs a public track-and-trace URL from a barcode.
		 * @param string $barcode
		 * @param string $postalCode
		 * @param int|null $carrierId
		 * @return string|null
		 */
		private function buildTrackingUrl(string $barcode, string $postalCode, ?int $carrierId): ?string {
			return match ($carrierId) {
				1 => "https://postnl.nl/tracktrace/?B={$barcode}&P=" . rawurlencode($postalCode) . "&D=NL&T=C",
				2 => "https://track.bpost.be/bpb/sites/track/index.html#/tracking?barcode={$barcode}",
				default => null,
			};
		}
		
		/**
		 * Builds a human-readable label for a delivery timeslot.
		 * @param \DateTimeImmutable|null $date
		 * @param string $start e.g. '09:00'
		 * @param string $end e.g. '12:00'
		 * @param string $type e.g. 'standard', 'morning', 'avond'
		 * @return string
		 */
		private function buildDeliveryLabel(?\DateTimeImmutable $date, string $start, string $end, string $type): string {
			$dateStr = $date ? $date->format('d-m-Y') : '';
			
			return match ($type) {
				'morning' => trim("{$dateStr} Morning {$start}–{$end}"),
				'avond'   => trim("{$dateStr} Evening {$start}–{$end}"),
				default   => trim("{$dateStr} {$start}–{$end}"),
			};
		}
	}