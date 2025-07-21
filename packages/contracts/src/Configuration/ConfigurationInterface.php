<?php
	
	namespace Quellabs\Contracts\Configuration;
	
	/**
	 * Interface for configuration management implementations
	 *
	 * Defines the contract for classes that handle application configuration data
	 * with support for type casting, default values, and dynamic updates.
	 */
	interface ConfigurationInterface {
		
		/**
		 * Get the entire configuration array
		 * @return array Complete configuration data
		 */
		public function all(): array;
		
		/**
		 * Check if a configuration key exists
		 * @param string $key The configuration key to check
		 * @return bool True if key exists, false otherwise
		 */
		public function has(string $key): bool;
		
		/**
		 * Get a specific configuration value
		 * @param string $key The configuration key to retrieve
		 * @param mixed $default Default value if key doesn't exist (default: null)
		 * @return mixed The configuration value or default if not found
		 */
		public function get(string $key, mixed $default = null): mixed;
		
		/**
		 * Get configuration value with automatic type casting
		 * @param string $key The configuration key to retrieve
		 * @param string $type Target type for casting (string, int, float, bool, array)
		 * @param mixed $default Default value if key doesn't exist (default: null)
		 * @return mixed The type-cast configuration value or default
		 */
		public function getAs(string $key, string $type, mixed $default = null): mixed;
		
		/**
		 * Get all configuration keys
		 * @return array Array of all configuration keys
		 */
		public function keys(): array;
		
		/**
		 * Set a configuration value
		 * @param string $key The configuration key to set
		 * @param mixed $value The value to assign to the key
		 * @return void
		 */
		public function set(string $key, mixed $value): void;
		
		/**
		 * Merge additional configuration data into existing config
		 * Existing keys will be overwritten by new values
		 * @param array $config Configuration array to merge
		 * @return void
		 */
		public function merge(array $config): void;
	}