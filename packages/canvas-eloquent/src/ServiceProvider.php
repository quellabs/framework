<?php

	namespace Quellabs\CanvasEloquent;

	use Illuminate\Database\Capsule\Manager as Capsule;
	use Illuminate\Database\ConnectionInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;

	/**
	 * Registers a singleton Eloquent connection with the dependency injection
	 * container, bootstrapped via Illuminate's standalone Capsule manager.
	 * Reads named connections from config/eloquent.php and resolves the
	 * connection named by the 'default' key.
	 */
	class ServiceProvider extends BaseServiceProvider {

		/**
		 * Cached Capsule manager instance
		 * @var Capsule|null
		 */
		private static ?Capsule $capsule = null;

		/**
		 * Returns the default per-connection configuration values
		 * @return array<string, mixed> Array of default configuration values
		 */
		public static function getConnectionDefaults(): array {
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
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool True if this provider supports the Eloquent connection interface
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === ConnectionInterface::class;
		}

		/**
		 * Resolves the default Eloquent connection, booting the Capsule manager on first use
		 * @param string $className The class name to instantiate (ConnectionInterface)
		 * @param array $dependencies Additional autowired dependencies (currently unused)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext Optional method context
		 * @return object The configured default Eloquent connection instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			$config  = $this->getConfig();
			$default = is_string($config['default'] ?? null) ? $config['default'] : 'default';

			return $this->getCapsule()->getConnection($default);
		}

		/**
		 * Returns the booted Capsule manager, creating and registering it globally on first use
		 * so Eloquent models (which resolve connections statically) can find it
		 * @return Capsule
		 */
		private function getCapsule(): Capsule {
			if (self::$capsule !== null) {
				return self::$capsule;
			}

			$capsule = new Capsule();

			foreach ($this->buildConnections() as $name => $connectionConfig) {
				$capsule->addConnection($connectionConfig, $name);
			}

			$capsule->setAsGlobal();
			$capsule->bootEloquent();

			return self::$capsule = $capsule;
		}

		/**
		 * Builds the full set of named connection configs from config/eloquent.php,
		 * merging each connection's values over the driver defaults
		 * @return array<string, array<string, mixed>> Connection configs keyed by connection name
		 */
		private function buildConnections(): array {
			$defaults    = self::getConnectionDefaults();
			$config      = $this->getConfig();
			$connections = is_array($config['connections'] ?? null) ? $config['connections'] : [];

			if ($connections === []) {
				return ['default' => $defaults];
			}

			$result = [];

			foreach ($connections as $name => $connectionConfig) {
				$result[$name] = array_merge($defaults, is_array($connectionConfig) ? $connectionConfig : []);
			}

			return $result;
		}
	}
