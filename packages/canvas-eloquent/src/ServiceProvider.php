<?php

	namespace Quellabs\CanvasEloquent;

	use Illuminate\Database\Capsule\Manager as Capsule;
	use Illuminate\Database\ConnectionInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Registers a singleton Eloquent connection, bootstrapped via Illuminate's standalone Capsule manager.
	 * Model parameter binding is handled separately by ModelBindingServiceProvider, which relies on the
	 * Capsule booted here for Eloquent's static connection resolver.
	 */
	class ServiceProvider extends BaseServiceProvider {

		/**
		 * Cached Capsule manager instance
		 * @var Capsule|null
		 */
		private static ?Capsule $capsule = null;

		/**
		 * Returns the default connection configuration values
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
		 * Merges the given config over the driver defaults and, for sqlite,
		 * resolves the database file to an absolute path and creates it if
		 * missing. Illuminate's own SQLiteConnector falls back to a base_path()
		 * helper for a relative or missing path, which only exists under the
		 * full Laravel framework and isn't available to this standalone Capsule.
		 * @param array<string, mixed> $config
		 * @return array<string, mixed>
		 */
		public static function prepareConnectionConfig(array $config): array {
			$connection = array_merge(self::getConnectionDefaults(), $config);

			if ($connection['driver'] === 'sqlite' && is_string($connection['database'])) {
				$connection['database'] = self::resolveSqliteDatabasePath($connection['database']);
			}

			return $connection;
		}

		/**
		 * Resolves a sqlite database path to an absolute path relative to the
		 * project root, creating the file (and its directory) if it doesn't
		 * exist yet. Leaves in-memory markers untouched.
		 * @param string $database
		 * @return string
		 */
		private static function resolveSqliteDatabasePath(string $database): string {
			if ($database === '' || $database === ':memory:' || str_contains($database, 'mode=memory')) {
				return $database;
			}

			if (!self::isAbsolutePath($database)) {
				$database = ComposerUtils::getProjectRoot() . '/' . $database;
			}

			if (!file_exists($database)) {
				$directory = dirname($database);

				if (!is_dir($directory)) {
					mkdir($directory, 0777, true);
				}

				touch($database);
			}

			return $database;
		}

		/**
		 * Determines whether a path is already absolute, on both Unix and Windows.
		 * @param string $path
		 * @return bool
		 */
		private static function isAbsolutePath(string $path): bool {
			return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1);
		}

		/**
		 * Applies the configuration and boots the Capsule immediately, so Eloquent models work without anything ever type-hinting ConnectionInterface.
		 * @param array<string, mixed> $config Configuration array to apply to this provider
		 * @return void
		 */
		public function setConfig(array $config): void {
			parent::setConfig($config);
			$this->getCapsule();
		}

		/**
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array<string, mixed> $metadata Metadata for filtering
		 * @return bool True if this provider supports the Eloquent connection interface
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === ConnectionInterface::class;
		}

		/**
		 * Resolves the Eloquent connection, booting the Capsule manager on first use
		 * @param string $className The class name to instantiate (ConnectionInterface)
		 * @param array<int|string, mixed> $dependencies Additional autowired dependencies (currently unused)
		 * @param array<string, mixed> $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext Optional method context
		 * @return object The configured Eloquent connection instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			return $this->getCapsule()->getConnection();
		}

		/**
		 * Returns the booted Capsule manager, creating and registering it globally on first use.
		 * @return Capsule
		 */
		private function getCapsule(): Capsule {
			if (self::$capsule !== null) {
				return self::$capsule;
			}

			$capsule = new Capsule();
			$capsule->addConnection(self::prepareConnectionConfig($this->getConfig()));
			$capsule->setAsGlobal();
			$capsule->bootEloquent();

			return self::$capsule = $capsule;
		}
	}
