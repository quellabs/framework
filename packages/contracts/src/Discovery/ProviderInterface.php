<?php
	
	namespace Quellabs\Contracts\Discovery;
	
	interface ProviderInterface {
		
		/**
		 * Retrieves metadata about the provider's capabilities and attributes.
		 * This method returns detailed information that describes the provider's
		 * functionality, supported features, version information, and other
		 * relevant configuration details needed for discovery and integration.
		 * @return array<string, mixed> Associative array of metadata key-value pairs
		 */
		public static function getMetadata(): array;
		
		/**
		 * Returns the configuration
		 * @return array
		 */
		public function getConfig(): array;
		
		/**
		 * Sets configuration
		 * @return void
		 */
		public function setConfig(array $config): void;
	}