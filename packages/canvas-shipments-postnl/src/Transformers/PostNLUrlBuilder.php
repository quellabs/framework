<?php
	
	namespace Quellabs\Shipments\PostNL\Transformers;
	
	class PostNLUrlBuilder {
		
		/**
		 * Constructs the public PostNL track-and-trace URL for a barcode.
		 * @param string $barcode
		 * @param string $postalCode
		 * @return string
		 */
		public static function trackingUrl(string $barcode, string $postalCode): string {
			return 'https://postnl.nl/tracktrace/?B=' . rawurlencode($barcode)
				. '&P=' . rawurlencode($postalCode)
				. '&D=NL&T=C';
		}
	}