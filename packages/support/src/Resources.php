<?php
	
	namespace Quellabs\Support;
	
	class Resources {
		
		/**
		 * Returns the absolute path to the bundled CA certificate file.
		 * Used for SSL peer verification in outgoing HTTP requests.
		 * @return string
		 */
		public static function cacertPem(): string {
			return dirname(__DIR__) . '/resources/cacert.pem';
		}
	}