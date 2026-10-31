<?php

	namespace Quellabs\CanvasEloquent;

	use Illuminate\Database\Capsule\Manager as Capsule;
	use Illuminate\Database\ConnectionInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;

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
			$capsule->addConnection(array_merge(self::getConnectionDefaults(), $this->getConfig()));
			$capsule->setAsGlobal();
			$capsule->bootEloquent();

			return self::$capsule = $capsule;
		}
	}
