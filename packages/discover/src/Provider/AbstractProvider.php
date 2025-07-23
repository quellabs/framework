<?php
	
	namespace Quellabs\Discover\Provider;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
	/**
	 * AbstractProvider class implements the basic functionality for a provider.
	 * This abstract class serves as a base implementation of the ProviderInterface.
	 * Specific providers should extend this class to implement their unique functionality.
	 */
	class AbstractProvider implements ProviderInterface {
		
		/**
		 * Configuration settings for the provider.
		 * @var array
		 */
		private array $config;
		
		/**
		 * Retrieves metadata associated with this provider.
		 * @return array An associative array of metadata key-value pairs
		 */
		public static function getMetadata(): array {
			return [];
		}
		
		/**
		 * Returns the default configuration settings for this provider.
		 * Child classes can override this to provide their specific defaults.
		 * @return array Default configuration values
		 */
		public static function getDefaults(): array {
			return [];
		}
		
		/**
		 * Returns the current configuration settings for this provider.
		 * @return array The complete configuration array for this provider
		 */
		public function getConfig(): array {
			return $this->config;
		}
		
		/**
		 * Sets the configuration for this provider.
		 * @param array $config Configuration array to apply to this provider
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = array_merge(static::getDefaults(), $config);
		}
		
		/**
		 * Return true if the config value is present
		 * @param string $key
		 * @return mixed
		 */
		public function hasConfigValue(string $key): bool {
			return isset($this->config[$key]);
		}
		
		/**
		 * Returns a certain value from the config array
		 * @param string $key
		 * @param mixed $default
		 * @return mixed
		 */
		public function getConfigValue(string $key, mixed $default = null): mixed {
			return $this->config[$key] ?? $default;
		}
	}