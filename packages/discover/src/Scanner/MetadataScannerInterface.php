<?php
	
	namespace Quellabs\Discover\Scanner;
	
	/**
	 * Defines the contract for scanners that collect raw scalar metadata
	 * from package discovery configurations.
	 *
	 * Unlike ScannerInterface (which produces ProviderDefinition objects),
	 * implementations of this interface collect string-array values advertised
	 * by packages under named keys within a family section — such as directory
	 * paths, class name lists, or other string-array fields.
	 *
	 * collect() returns the full key/value map for the family, e.g.:
	 *   ['controllers' => ['/abs/path/a', '/abs/path/b'], 'middleware' => [...]]
	 *
	 * Results from multiple packages are merged by key across all packages.
	 */
	interface MetadataScannerInterface {
		
		/**
		 * Return the family name this scanner reads from within the discovery section.
		 * Used by Discover to key and retrieve results via getMetadata().
		 * @return string e.g. 'canvas'
		 */
		public function getFamilyName(): string;
		
		/**
		 * Collect all key/value metadata for this scanner's family across all packages.
		 * Results are grouped by package name to preserve the relationship between keys
		 * declared together in the same composer.json.
		 * @return array<string, array<string, string[]>> e.g. ['vendor/pkg' => ['controllers' => [...], ...]]
		 */
		public function collect(): array;
	}