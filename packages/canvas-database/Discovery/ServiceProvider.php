<?php
	
	namespace Quellabs\CanvasDatabase\Discovery;
	
	use Cake\Database\Connection;
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	
	/**
	 * Registers a singleton CakePHP Database Connection instance with the dependency injection
	 * container. This allows raw database access throughout the application using CakePHP's
	 * database layer.
	 */
	class ServiceProvider extends BaseServiceProvider {
		
		/**
		 * Cached singleton instance of the database connection
		 * @var Connection|null
		 */
		private static ?Connection $instance = null;
		
		/**
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool True if this provider supports the CakePHP Connection class
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === Connection::class;
		}
		
		/**
		 * Returns the default database configuration values
		 * @return array<string, mixed> Array of default configuration values
		 */
		public static function getDefaults(): array {
			return [
				'driver'        => 'mysql',           // Database driver (mysql, postgres, sqlite, sqlserver)
				'host'          => 'localhost',       // Database server hostname
				'database'      => '',                // Database name
				'username'      => '',                // Database username
				'password'      => '',                // Database password
				'port'          => 3306,              // Database port (3306 for MySQL)
				'encoding'      => 'utf8mb4',         // Character encoding (supports full Unicode)
				'timezone'      => 'UTC',             // Timezone for datetime operations
				'flags'         => [],                // Driver-specific connection flags
				'cacheMetadata' => true,              // Whether to cache database metadata
				'log'           => false,             // Whether to log queries
			];
		}
		
		/**
		 * Creates a new Connection instance with proper configuration
		 * @param string $className The class name to instantiate (Connection)
		 * @param array $dependencies Additional autowired dependencies (currently unused)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContext|null $methodContext Optional method context
		 * @return object A configured Connection instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext = null): object {
			// Return existing instance if already created (singleton behavior)
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Build the connection configuration from config file and defaults
			$config = $this->buildConnectionConfig();
			
			// Create and cache the connection instance
			self::$instance = new Connection($config);
			
			// Return the singleton instance
			return self::$instance;
		}
		
		/**
		 * Builds the connection configuration array from config data and defaults
		 * @return array<string, mixed> Complete connection configuration array
		 */
		private function buildConnectionConfig(): array {
			// Get default configuration values
			$defaults = self::getDefaults();
			
			// Load user configuration from config file
			$configData = $this->getConfig();
			
			// Build final configuration with defaults as fallback
			return [
				'driver'        => $this->resolveDriver($configData['driver'] ?? $defaults['driver']),
				'host'          => $configData['host'] ?? $defaults['host'],
				'username'      => $configData['username'] ?? $defaults['username'],
				'password'      => $configData['password'] ?? $defaults['password'],
				'database'      => $configData['database'] ?? $defaults['database'],
				'port'          => $configData['port'] ?? $defaults['port'],
				'encoding'      => $configData['encoding'] ?? $defaults['encoding'],
				'timezone'      => $configData['timezone'] ?? $defaults['timezone'],
				'flags'         => $configData['flags'] ?? $defaults['flags'],
				'cacheMetadata' => $configData['cacheMetadata'] ?? $defaults['cacheMetadata'],
				'log'           => $configData['log'] ?? $defaults['log'],
			];
		}
		
		/**
		 * Resolves a driver name to its fully qualified class name
		 *
		 * Converts short driver names (mysql, postgres, etc.) to their corresponding
		 * CakePHP driver class names. If a fully qualified class name is already
		 * provided, it is returned as-is.
		 *
		 * @param string $driver The driver name or class to resolve
		 * @return string The fully qualified driver class name
		 */
		private function resolveDriver(string $driver): string {
			// Map of short driver names to fully qualified class names
			$driverMap = [
				'mysql'     => \Cake\Database\Driver\Mysql::class,
				'postgres'  => \Cake\Database\Driver\Postgres::class,
				'sqlite'    => \Cake\Database\Driver\Sqlite::class,
				'sqlserver' => \Cake\Database\Driver\Sqlserver::class,
			];
			
			// Return the mapped class name, or the original value if not in map
			// This allows users to specify either short names or full class names
			return $driverMap[$driver] ?? $driver;
		}
	}