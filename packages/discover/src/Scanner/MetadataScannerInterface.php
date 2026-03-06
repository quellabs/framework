<?php
	
	namespace Quellabs\Discover\Scanner;
	
	/**
	 * Defines the contract for scanners that collect raw scalar metadata
	 * from package discovery configurations.
	 *
	 * Unlike ScannerInterface (which produces ProviderDefinition objects),
	 * implementations of this interface collect values advertised by packages
	 * under named keys within a family section. Values are returned as-is —
	 * scalars stay scalar, arrays stay arrays.
	 *
	 * collect() returns results grouped by package name, e.g.:
	 *   ['vendor/pkg' => ['controller' => 'src/Controllers', 'middleware' => [...]]]
	 */
	interface MetadataScannerInterface {
		
		/**
		 * Return the family name this scanner reads from within the discovery section.
		 * Used by Discover to key and retrieve results via getFamilyMetadata().
		 * @return string e.g. 'canvas'
		 */
		public function getFamilyName(): string;
		
		/**
		 * Collect all key/value metadata for this scanner's family across all packages.
		 * Results are grouped by package name to preserve the relationship between keys
		 * declared together in the same composer.json. Values are returned as-is.
		 * @return array<string, array<string, mixed>> e.g. ['vendor/pkg' => ['controller' => 'src/Controllers']]
		 */
		public function collect(): array;
	}