<?php
	
	namespace Quellabs\CanvasObjectQuel\Discovery;
	
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	
	/**
	 * Registers a singleton ObjectQuel Configuration instance with the dependency injection
	 * container. This provider creates Configuration objects with settings loaded from the
	 * application's config file. The Configuration is used by EntityManager and other
	 * ObjectQuel components to determine entity paths, proxy generation settings, and
	 * metadata caching options.
	 */
	class ConfigurationServiceProvider extends BaseServiceProvider {
		
		/**
		 * Cached singleton instance of the Configuration
		 * @var Configuration|null
		 */
		private static ?Configuration $instance = null;
		
		/**
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool True if this provider supports the Configuration class
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === Configuration::class;
		}
		
		/**
		 * Returns the default ObjectQuel configuration values
		 * @return array<string, mixed> Array of default configuration values
		 */
		public static function getDefaults(): array {
			return [
				'migrations_path'     => '',                                      // Path to database migration files
				'entity_namespace'    => '',                                      // Root namespace for entity classes
				'entity_path'         => '',                                      // Directory containing entity classes
				'proxy_namespace'     => 'Quellabs\\ObjectQuel\\Proxy\\Runtime', // Namespace for generated proxy classes
				'proxy_path'          => null,                                    // Directory for generated proxy classes
				'metadata_cache_path' => ''                                       // Path for cached entity metadata
			];
		}
		
		/**
		 * Creates a new Configuration instance with settings from config file
		 *
		 * Implements singleton pattern to ensure only one Configuration exists per application
		 * lifecycle. Configuration is loaded from the application's config system and merged
		 * with defaults.
		 *
		 * @param string $className The class name to instantiate (Configuration)
		 * @param array $dependencies Additional autowired dependencies (currently unused)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContext|null $methodContext Optional method context
		 * @return object A configured Configuration instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext = null): object {
			// Return existing instance if already created (singleton behavior)
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Get default configuration values
			$defaults = self::getDefaults();
			
			// Load user configuration from config file
			$configData = $this->getConfig();
			
			// Create new configuration instance
			$config = new Configuration();
			
			// Configure entity class directory
			$config->setEntityPath($configData["entity_path"] ?? $defaults["entity_path"] ?? '');
			
			// Configure proxy class generation directory
			$config->setProxyDir($configData["proxy_path"] ?? $defaults["proxy_path"] ?? null);
			
			// Configure entity class namespace
			$config->setEntityNameSpace($configData["entity_namespace"] ?? $defaults["entity_namespace"] ?? null);
			
			// Enable metadata caching if path is provided
			if (!empty($configData["metadata_cache_path"])) {
				$config->setUseMetadataCache(true);
				$config->setMetadataCachePath($configData["metadata_cache_path"]);
			}
			
			// Cache and return the instance
			self::$instance = $config;
			return self::$instance;
		}
	}