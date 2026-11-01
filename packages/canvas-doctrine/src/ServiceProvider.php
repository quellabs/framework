<?php
	
	namespace Quellabs\CanvasDoctrine;
	
	use Composer\InstalledVersions;
	use Doctrine\DBAL\Connection;
	use Doctrine\DBAL\DriverManager;
	use Doctrine\ORM\Configuration;
	use Doctrine\ORM\EntityManager;
	use Doctrine\ORM\EntityManagerInterface;
	use Doctrine\ORM\ORMSetup;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * Provides one configured Doctrine EntityManager and its connection.
	 * @phpstan-type ConnectionParams array{driver: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv', host: string, port: int, dbname: string, user: string, password: string, charset: string}
	 */
	class ServiceProvider extends BaseServiceProvider {
		/**
		 * DBAL driver identifiers accepted by DriverManager::getConnection()'s 'driver' key.
		 */
		private const VALID_DRIVERS = ['pdo_mysql', 'pdo_sqlite', 'pdo_pgsql', 'pdo_oci', 'oci8', 'ibm_db2', 'pdo_sqlsrv', 'mysqli', 'pgsql', 'sqlsrv', 'sqlite3'];
		
		private ?EntityManager $entityManager = null;
		
		/**
		 * Checks whether this provider owns the requested service.
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return in_array($className, [EntityManager::class, EntityManagerInterface::class, Connection::class], true);
		}
		
		/**
		 * Returns the shared manager or its connection.
		 * @param string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object
		 * @throws \InvalidArgumentException
		 * @throws \Doctrine\DBAL\Exception
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			$manager = $this->getEntityManager();
			return $className === Connection::class ? $manager->getConnection() : $manager;
		}
		
		/**
		 * Builds the single manager from the published configuration.
		 * @return EntityManager
		 * @throws \InvalidArgumentException
		 * @throws \Doctrine\DBAL\Exception
		 */
		private function getEntityManager(): EntityManager {
			if ($this->entityManager !== null) {
				return $this->entityManager;
			}
			
			$config = $this->getConfig();
			$connection = DriverManager::getConnection($this->resolveConnectionConfig($config));
			$ormConfig = $this->createOrmConfiguration($this->resolveEntityPaths($config), $this->resolveDevelopmentMode($config));
			return $this->entityManager = new EntityManager($connection, $ormConfig);
		}
		
		/**
		 * Builds the attribute-driven ORM configuration. createAttributeMetadataConfig()
		 * only exists on doctrine/orm 3.5+, so older installed versions keep using the
		 * deprecated createAttributeMetadataConfiguration() (harmless pre-PHP 8.4, where
		 * it triggers no notice). The installed version is checked directly rather than
		 * with method_exists(), since analysis tools only ever see whichever version is
		 * actually vendored and would flag a reflection-based check as always true. On
		 * PHP 8.4+ with 3.5+ available, native lazy objects replace proxy-class
		 * generation, so no proxy directory needs configuring.
		 * @param string[] $paths
		 * @param bool $developmentMode
		 * @return Configuration
		 */
		private function createOrmConfiguration(array $paths, bool $developmentMode): Configuration {
			$ormVersion = InstalledVersions::getVersion('doctrine/orm');
			
			if (PHP_VERSION_ID < 80400 || $ormVersion === null || version_compare($ormVersion, '3.5.0', '<')) {
				return ORMSetup::createAttributeMetadataConfiguration($paths, $developmentMode);
			}
			
			$config = ORMSetup::createAttributeMetadataConfig($paths, $developmentMode);
			$config->enableNativeLazyObjects(true);
			return $config;
		}
		
		/**
		 * Validates and returns the configured entity paths.
		 * @param array<string, mixed> $config
		 * @return string[]
		 * @throws \InvalidArgumentException
		 */
		private function resolveEntityPaths(array $config): array {
			$paths = $config['entity_paths'] ?? [ComposerUtils::getProjectRoot() . '/src/Entities'];
			
			if (!is_array($paths) || $paths === [] || !array_is_list($paths) || array_filter($paths, fn($path) => !is_string($path) || $path === '') !== []) {
				throw new \InvalidArgumentException('doctrine.entity_paths must be a non-empty array of paths');
			}
			
			return $paths;
		}
		
		/**
		 * Validates the single connection definition and merges it with defaults.
		 * @param array<string, mixed> $config
		 * @return ConnectionParams
		 * @throws \InvalidArgumentException
		 */
		private function resolveConnectionConfig(array $config): array {
			$connection = $config['connection'] ?? [];
			
			// Only one connection is supported; reject a list of connections or a 'connections' key.
			if (!is_array($connection) || ($connection !== [] && array_is_list($connection)) || array_key_exists('connections', $connection) || array_key_exists('connections', $config)) {
				throw new \InvalidArgumentException('Doctrine supports one connection; configure doctrine.connection directly');
			}
			
			$driver = $connection['driver'] ?? 'pdo_mysql';

			// Catch an unsupported driver here, not as a less clear failure inside DriverManager.
			if (!is_string($driver) || !in_array($driver, self::VALID_DRIVERS, true)) {
				throw new \InvalidArgumentException('doctrine.connection.driver must be one of: ' . implode(', ', self::VALID_DRIVERS));
			}

			$host = $connection['host'] ?? 'localhost';
			$port = $connection['port'] ?? 3306;
			$dbname = $connection['dbname'] ?? '';
			$user = $connection['user'] ?? '';
			$password = $connection['password'] ?? '';
			$charset = $connection['charset'] ?? 'utf8mb4';

			// Narrow each value explicitly so the returned array matches DBAL's expected param shape.
			if (!is_string($host) || !is_int($port) || !is_string($dbname) || !is_string($user) || !is_string($password) || !is_string($charset)) {
				throw new \InvalidArgumentException('doctrine.connection host, port, dbname, user, password, and charset must be string/int as shown in the defaults');
			}
			
			return [
				'driver'   => $driver,
				'host'     => $host,
				'port'     => $port,
				'dbname'   => $dbname,
				'user'     => $user,
				'password' => $password,
				'charset'  => $charset
			];
		}
		
		/**
		 * Validates and returns the development-mode flag.
		 * @param array<string, mixed> $config
		 * @return bool
		 * @throws \InvalidArgumentException
		 */
		private function resolveDevelopmentMode(array $config): bool {
			$developmentMode = $config['development_mode'] ?? false;
			
			if (!is_bool($developmentMode)) {
				throw new \InvalidArgumentException('doctrine.development_mode must be a boolean');
			}
			
			return $developmentMode;
		}
	}