<?php

	namespace Quellabs\CanvasDoctrine\Sculpt;

	use Doctrine\Migrations\Configuration\Configuration;
	use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
	use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
	use Doctrine\Migrations\DependencyFactory;
	use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
	use Quellabs\CanvasDoctrine\EntityManagerFactory;

	/**
	 * Builds the doctrine/migrations DependencyFactory used by the Sculpt
	 * migration commands, wired to the same EntityManager/connection
	 * EntityManagerFactory builds for the DI ServiceProvider, so schema-diff
	 * generation compares against the real entity metadata.
	 */
	final class MigrationsDependencyFactory {
		/**
		 * Builds the DependencyFactory from the canvas-doctrine config array.
		 * @param array<string, mixed> $config
		 * @return DependencyFactory
		 * @throws \InvalidArgumentException
		 * @throws \Doctrine\DBAL\Exception
		 */
		public static function create(array $config): DependencyFactory {
			$entityManager = EntityManagerFactory::create($config);

			$configuration = new Configuration();
			$configuration->addMigrationsDirectory(self::resolveMigrationsNamespace($config), self::resolveMigrationsPath($config));
			$configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());

			return DependencyFactory::fromEntityManager(
				new ExistingConfiguration($configuration),
				new ExistingEntityManager($entityManager)
			);
		}

		/**
		 * Validates and returns the configured migrations directory.
		 * @param array<string, mixed> $config
		 * @return string
		 * @throws \InvalidArgumentException
		 */
		private static function resolveMigrationsPath(array $config): string {
			$path = $config['migrations_path'] ?? null;

			if (!is_string($path) || $path === '') {
				throw new \InvalidArgumentException('doctrine.migrations_path must be a non-empty string');
			}

			return $path;
		}

		/**
		 * Validates and returns the configured migrations namespace.
		 * @param array<string, mixed> $config
		 * @return string
		 * @throws \InvalidArgumentException
		 */
		private static function resolveMigrationsNamespace(array $config): string {
			$namespace = $config['migrations_namespace'] ?? null;

			if (!is_string($namespace) || $namespace === '') {
				throw new \InvalidArgumentException('doctrine.migrations_namespace must be a non-empty string');
			}

			return $namespace;
		}
	}
