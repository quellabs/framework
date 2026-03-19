<?php
	
	namespace Quellabs\Contracts\Configuration;
	
	/**
	 * Provides access to configuration files.
	 * Implementations are responsible for loading, caching, and merging
	 * base config files with their .local.php overrides.
	 */
	interface ConfigProviderInterface {
		
		/**
		 * Load a config file with .local.php override support
		 * @param string $filename Filename to load (with or without .php extension)
		 * @return ConfigurationInterface
		 */
		public function loadConfigFile(string $filename): ConfigurationInterface;
	}