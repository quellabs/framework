<?php
	
	namespace Quellabs\CanvasObjectQuel;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	use Quellabs\Support\ComposerUtils;
	
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
		 * @param array<string, mixed> $metadata Metadata for filtering
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
		 * @param array<string, mixed> $dependencies Additional autowired dependencies (currently unused)
		 * @param array<string, mixed> $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext Optional method context
		 * @return object A configured Configuration instance
		 */
		public function createInstance(
			string $className,
			array $dependencies,
			array $metadata,
			?MethodContextInterface $methodContext = null
		): object {
			// Return existing instance if already created (singleton behavior)
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Get default configuration values
			$defaults = self::getDefaults();
			$configData = $this->getConfig();
			$config = new Configuration();
			
			// Configure entity class directory
			$defaultEntityPath = ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Entities';
			$entityPath = $configData["entity_path"] ?? $defaults["entity_path"] ?? $defaultEntityPath;
			$config->setEntityPath(is_string($entityPath) ? $entityPath : $defaultEntityPath);
			
			// Configure proxy class generation directory
			$defaultProxyPath = ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'objectquel' . DIRECTORY_SEPARATOR . 'proxies';
			$proxyPath = $configData["proxy_path"] ?? $defaults["proxy_path"] ?? $defaultProxyPath;
			$config->setProxyDir(is_string($proxyPath) ? $proxyPath : $defaultProxyPath);
			
			// Configure entity class namespace
			$defaultEntityNamespace = 'App\\Entities';
			$entityNamespace = $configData["entity_namespace"] ?? $defaults["entity_namespace"] ?? $defaultEntityNamespace;
			$config->setEntityNameSpace(is_string($entityNamespace) ? $entityNamespace : $defaultEntityNamespace);
			
			// Development mode
			if ($configData["development_mode"] ?? false) {
				$config->setDevelopmentMode(true);
			}
			
			// Cache and return the instance
			self::$instance = $config;
			return self::$instance;
		}
	}