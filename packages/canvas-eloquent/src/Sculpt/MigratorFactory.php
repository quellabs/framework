<?php

	namespace Quellabs\CanvasEloquent\Sculpt;

	use Illuminate\Database\Capsule\Manager as Capsule;
	use Illuminate\Database\Migrations\DatabaseMigrationRepository;
	use Illuminate\Database\Migrations\Migrator;
	use Illuminate\Filesystem\Filesystem;
	use Illuminate\Support\Facades\Facade;
	use Quellabs\CanvasEloquent\ServiceProvider;

	/**
	 * Builds the illuminate/database Migrator used by the Sculpt migration
	 * commands, wired to its own Capsule connection built from the same
	 * config and defaults as the DI ServiceProvider, since the two run in
	 * separate bootstraps and each need their own instance. Also binds the
	 * 'db'/'db.schema' container entries the Schema facade expects, since
	 * migration files written against the standard stubs call it directly.
	 */
	final class MigratorFactory {
		
		/**
		 * Builds the Migrator from the canvas-eloquent config array.
		 * @param array<string, mixed> $config
		 * @return Migrator
		 * @throws \InvalidArgumentException
		 */
		public static function create(array $config): Migrator {
			$capsule = new Capsule();
			$capsule->addConnection(ServiceProvider::prepareConnectionConfig($config));

			$container = $capsule->getContainer();
			$container->instance('db', $capsule->getDatabaseManager());
			$container->bind('db.schema', fn($app) => $app['db']->connection()->getSchemaBuilder());
			Facade::setFacadeApplication($container);

			$repository = new DatabaseMigrationRepository($capsule->getDatabaseManager(), self::resolveMigrationsTable($config));

			if (!$repository->repositoryExists()) {
				$repository->createRepository();
			}

			return new Migrator($repository, $capsule->getDatabaseManager(), new Filesystem());
		}

		/**
		 * Validates and returns the configured migrations directory.
		 * @param array<string, mixed> $config
		 * @return string
		 * @throws \InvalidArgumentException
		 */
		public static function resolveMigrationsPath(array $config): string {
			$path = $config['migrations_path'] ?? null;

			if (!is_string($path) || $path === '') {
				throw new \InvalidArgumentException('eloquent.migrations_path must be a non-empty string');
			}

			return $path;
		}

		/**
		 * Validates and returns the configured migrations table name.
		 * @param array<string, mixed> $config
		 * @return string
		 * @throws \InvalidArgumentException
		 */
		private static function resolveMigrationsTable(array $config): string {
			$table = $config['migrations_table'] ?? 'eloquent_migrations';

			if (!is_string($table) || $table === '') {
				throw new \InvalidArgumentException('eloquent.migrations_table must be a non-empty string');
			}

			return $table;
		}
	}
