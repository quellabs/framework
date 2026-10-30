<?php

	namespace Quellabs\CanvasEloquent;

	use Illuminate\Database\Capsule\Manager as Capsule;
	use Illuminate\Database\ConnectionInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;

	/**
	 * Registers a singleton Eloquent connection with the dependency injection
	 * container, bootstrapped via Illuminate's standalone Capsule manager.
	 */
	class ServiceProvider extends BaseServiceProvider {

		/**
		 * Cached singleton instance of the Eloquent connection
		 * @var ConnectionInterface|null
		 */
		private static ?ConnectionInterface $instance = null;

		/**
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool True if this provider supports the Eloquent connection interface
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === ConnectionInterface::class;
		}

		/**
		 * Returns the default database configuration values
		 * @return array<string, mixed> Array of default configuration values
		 */
		public static function getDefaults(): array {
			return [
				'driver'    => 'mysql',               // Database driver (mysql, pgsql, sqlite, sqlsrv)
				'host'      => 'localhost',           // Database server hostname
				'database'  => '',                    // Database name
				'username'  => '',                    // Database username
				'password'  => '',                     // Database password
				'port'      => 3306,                   // Database port (3306 for MySQL)
				'charset'   => 'utf8mb4',               // Character set
				'collation' => 'utf8mb4_unicode_ci',    // Collation
				'prefix'    => '',                      // Table prefix
			];
		}

		/**
		 * Creates a new Eloquent connection via the Capsule manager
		 * @param string $className The class name to instantiate (ConnectionInterface)
		 * @param array $dependencies Additional autowired dependencies (currently unused)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext Optional method context
		 * @return object A configured Eloquent connection instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			// Return existing instance if already created (singleton behavior)
			if (self::$instance !== null) {
				return self::$instance;
			}

			// Build the connection configuration from config file and defaults
			$config = $this->buildConnectionConfig();

			// Boot a standalone Capsule manager and register the connection globally
			// so Eloquent models (which resolve connections statically) can find it
			$capsule = new Capsule();
			$capsule->addConnection($config);
			$capsule->setAsGlobal();
			$capsule->bootEloquent();

			// Cache and return the resulting connection
			self::$instance = $capsule->getConnection();

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
				'driver'    => $configData['driver'] ?? $defaults['driver'],
				'host'      => $configData['host'] ?? $defaults['host'],
				'database'  => $configData['database'] ?? $defaults['database'],
				'username'  => $configData['username'] ?? $defaults['username'],
				'password'  => $configData['password'] ?? $defaults['password'],
				'port'      => $configData['port'] ?? $defaults['port'],
				'charset'   => $configData['charset'] ?? $defaults['charset'],
				'collation' => $configData['collation'] ?? $defaults['collation'],
				'prefix'    => $configData['prefix'] ?? $defaults['prefix'],
			];
		}
	}
