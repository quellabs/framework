<?php

	namespace Quellabs\CanvasDoctrine;

	use Composer\InstalledVersions;
	use Doctrine\DBAL\DriverManager;
	use Doctrine\ORM\Configuration;
	use Doctrine\ORM\EntityManager;
	use Doctrine\ORM\ORMSetup;
	use Doctrine\ORM\Proxy\ProxyFactory;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Builds an EntityManager from the canvas-doctrine config array. Shared by
	 * the DI ServiceProvider (request lifecycle) and the Sculpt provider (CLI
	 * tooling), which run in separate bootstraps and each need their own instance.
	 * @phpstan-type ConnectionParams array{driver: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlsrv'|'pgsql'|'sqlsrv', host: string, port: int, dbname: string, user: string, password: string, charset: string}|array{driver: 'pdo_sqlite'|'sqlite3', path: string, user: string, password: string}|array{driver: 'pdo_sqlite'|'sqlite3', memory: true, user: string, password: string}
	 */
	final class EntityManagerFactory {
		/**
		 * DBAL driver identifiers accepted by DriverManager::getConnection()'s 'driver' key.
		 */
		private const array VALID_DRIVERS = ['pdo_mysql', 'pdo_sqlite', 'pdo_pgsql', 'pdo_oci', 'oci8', 'ibm_db2', 'pdo_sqlsrv', 'mysqli', 'pgsql', 'sqlsrv', 'sqlite3'];

		/**
		 * Builds a new EntityManager from the given config array.
		 * @param array<string, mixed> $config
		 * @return EntityManager
		 * @throws \InvalidArgumentException
		 * @throws \Doctrine\DBAL\Exception
		 */
		public static function create(array $config): EntityManager {
			$connection = DriverManager::getConnection(self::resolveConnectionConfig($config));
			$ormConfig = self::createOrmConfiguration(self::resolveEntityPaths($config), self::resolveDevelopmentMode($config));
			return new EntityManager($connection, $ormConfig);
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
		private static function createOrmConfiguration(array $paths, bool $developmentMode): Configuration {
			$ormVersion = InstalledVersions::getVersion('doctrine/orm');

			if (PHP_VERSION_ID < 80400 || $ormVersion === null || version_compare($ormVersion, '3.5.0', '<')) {
				$proxyDir = ComposerUtils::getProjectRoot() . '/storage/doctrine/proxies';
				$config = ORMSetup::createAttributeMetadataConfiguration($paths, $developmentMode, $proxyDir);

				if (!$developmentMode) {
					$config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);
				}

				return $config;
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
		private static function resolveEntityPaths(array $config): array {
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
		private static function resolveConnectionConfig(array $config): array {
			$connection = $config['connection'] ?? [];

			// Only one connection is supported; reject a list of connections or a 'connections' key.
			if (!is_array($connection) || ($connection !== [] && array_is_list($connection)) || array_key_exists('connections', $connection) || array_key_exists('connections', $config)) {
				throw new \InvalidArgumentException('Doctrine supports one connection; configure doctrine.connection directly');
			}

			// Catch an unsupported driver here, not as a less clear failure inside DriverManager.
			$driver = $connection['driver'] ?? 'pdo_mysql';

			if (!is_string($driver) || !in_array($driver, self::VALID_DRIVERS, true)) {
				throw new \InvalidArgumentException('doctrine.connection.driver must be one of: ' . implode(', ', self::VALID_DRIVERS));
			}

			$user = $connection['user'] ?? '';
			$password = $connection['password'] ?? '';

			if (!is_string($user) || !is_string($password)) {
				throw new \InvalidArgumentException('doctrine.connection user and password must be strings');
			}

			// DBAL's sqlite drivers key off 'path'/'memory', not 'dbname'; an empty dbname means in-memory.
			if ($driver === 'pdo_sqlite' || $driver === 'sqlite3') {
				return self::resolveSqliteConnectionConfig($driver, $connection, $user, $password);
			}

			// Narrow each value explicitly so the returned array matches DBAL's expected param shape.
			$host = $connection['host'] ?? 'localhost';
			$port = $connection['port'] ?? 3306;
			$dbname = $connection['dbname'] ?? '';
			$charset = $connection['charset'] ?? 'utf8mb4';

			if (!is_string($host) || !is_int($port) || !is_string($dbname) || !is_string($charset)) {
				throw new \InvalidArgumentException('doctrine.connection host, port, dbname, and charset must be string/int as shown in the defaults');
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
		 * Builds sqlite connection params. DBAL's sqlite drivers only recognize
		 * 'path'/'memory', not 'dbname'; an empty dbname means in-memory.
		 * @param 'pdo_sqlite'|'sqlite3' $driver
		 * @param array<string, mixed> $connection
		 * @param string $user
		 * @param string $password
		 * @return array{driver: 'pdo_sqlite'|'sqlite3', path: string, user: string, password: string}|array{driver: 'pdo_sqlite'|'sqlite3', memory: true, user: string, password: string}
		 * @throws \InvalidArgumentException
		 */
		private static function resolveSqliteConnectionConfig(string $driver, array $connection, string $user, string $password): array {
			$dbname = $connection['dbname'] ?? '';

			if (!is_string($dbname)) {
				throw new \InvalidArgumentException('doctrine.connection.dbname must be a string');
			}

			if ($dbname === '') {
				return ['driver' => $driver, 'memory' => true, 'user' => $user, 'password' => $password];
			}

			return ['driver' => $driver, 'path' => $dbname, 'user' => $user, 'password' => $password];
		}

		/**
		 * Validates and returns the development-mode flag.
		 * @param array<string, mixed> $config
		 * @return bool
		 * @throws \InvalidArgumentException
		 */
		private static function resolveDevelopmentMode(array $config): bool {
			$developmentMode = $config['development_mode'] ?? false;

			if (!is_bool($developmentMode)) {
				throw new \InvalidArgumentException('doctrine.development_mode must be a boolean');
			}

			return $developmentMode;
		}
	}
