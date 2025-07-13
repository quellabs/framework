<?php
	
	namespace Quellabs\Canvas\ObjectQuel\Discovery;
	
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\DependencyInjection\Provider\ServiceProvider;
	
	/**
	 * Service provider for ObjectQuel EntityManager
	 */
	class ObjectQuelServiceProvider extends ServiceProvider {
		
		/**
		 * Cached singleton instance of the template engine.
		 * @var EntityManager|null
		 */
		private static ?EntityManager $instance = null;
		
		/**
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool True if this provider supports the EntityManager class
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === EntityManager::class;
		}
		
		/**
		 * Returns the default configuration
		 * @return array[]
		 */
		public static function getDefaults(): array {
			return [
				'driver'              => 'mysql',
				'host'                => '',
				'database'            => '',
				'username'            => '',
				'password'            => '',
				'port'                => 3306,
				'encoding'            => 'utf8mb4',
				'collation'           => 'utf8mb4_unicode_ci',
				'migrations_path'     => '',
				'entity_namespace'    => '',
				'entity_path'         => '',
				'proxy_namespace'     => 'Quellabs\\ObjectQuel\\Proxy\\Runtime',
				'proxy_path'          => null,
				'metadata_cache_path' => ''
			];
		}
		
		/**
		 * Creates a new EntityManager instance with proper configuration
		 * @param string $className The class name to instantiate (EntityManager)
		 * @param array $dependencies Additional autowired dependencies (currently unused)
		 * @param MethodContext|null $methodContext
		 * @return object A configured EntityManager instance
		 */
		public function createInstance(string $className, array $dependencies, ?MethodContext $methodContext=null): object {
			// Return existing instance if already created (singleton behavior)
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Cache the instance for future requests (singleton pattern)
			self::$instance = new $className($this->createConfiguration());
			
			// Return the singleton instance
			return self::$instance;
		}
		
		/**
		 * Creates and configures the ObjectQuel Configuration object
		 * @return Configuration Fully configured Configuration instance
		 */
		private function createConfiguration(): Configuration {
			// Get database configuration from external config file
			$defaults = $this->getDefaults();
			$configData = $this->getConfig();
			
			// Create new configuration instance
			$config = new Configuration();
			
			// Directory containing entity classes
			$config->setEntityPath($configData["entity_path"] ?? $defaults["entity_path"] ?? '');
			
			// Directory for generated proxy classes
			$config->setProxyDir($configData["proxy_path"] ?? $defaults["proxy_path"]  ?? null);
			
			// Enable metadata caching
			if (!empty($configData["metadata_cache_path"])) {
				$config->setUseMetadataCache(true);
				$config->setMetadataCachePath($configData["metadata_cache_path"]);
			}
			
			// Configure database connection parameters with fallback defaults
			$config->setDatabaseParams(
				$configData['driver'] ?? $defaults['driver'] ?? 'mysql',        // Database driver (MySQL by default)
				$configData['host'] ?? $defaults['host'] ?? 'localhost',         // Database host
				$configData['database'] ?? $defaults['database'] ?? '',       // Database name
				$configData['username'] ?? $defaults['username'] ?? '',          // Database username
				$configData['password'] ?? $defaults['password'] ?? '',      // Database password
				$configData['port'] ?? $defaults['port'] ?? 3306,                // Database port (standard MySQL port)
				$configData['encoding'] ?? $defaults['encoding'] ?? 'utf8mb4' // Character encoding (supports full Unicode)
			);
			
			return $config;
		}
	}