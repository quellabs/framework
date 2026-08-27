<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Sqlite;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;

	/**
	 * Shared setup for the foreign key test suite.
	 *
	 * Every test in this suite runs against a fresh in-memory SQLite database and
	 * an EntityStore scoped to the fixture entities in
	 * tests/ObjectQuel/Fixtures/Entities — isolated from the rest of the ORM's
	 * MySQL-backed EntityManager ($GLOBALS['test_em']), and fast enough to run
	 * per-test with no shared state.
	 */
	trait FkTestSupport {

		/**
		 * require_once every fixture entity file once per process. Composer's
		 * PSR-4 autoload map does cover this directory, but
		 * EntityLocator::discoverEntities() requires the class to already be
		 * declared before it will register it (see class_exists() check there),
		 * and these fixtures are never referenced by name from test code, so
		 * nothing would trigger autoloading otherwise.
		 */
		protected static function loadFkFixtureEntities(): void {
			static $loaded = false;

			if ($loaded) {
				return;
			}

			foreach (glob(__DIR__ . '/../Fixtures/Entities/*.php') as $file) {
				require_once $file;
			}

			$loaded = true;
		}

		/**
		 * Builds an EntityStore scoped to the fixture entities. Pure annotation/
		 * reflection work — touches no database at all.
		 */
		protected function makeFkEntityStore(): EntityStore {
			self::loadFkFixtureEntities();

			$configuration = new Configuration();
			$configuration->setEntityPath(__DIR__ . '/../Fixtures/Entities');
			$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\Entities');
			$configuration->setUseMetadataCache(false);

			return new EntityStore($configuration);
		}

		/**
		 * Builds a DatabaseAdapter wrapping a fresh in-memory SQLite connection.
		 * Constructing it also exercises the PRAGMA foreign_keys=ON setup.
		 */
		protected function makeSqliteAdapter(): DatabaseAdapter {
			$connection = new Connection([
				'driver'   => Sqlite::class,
				'database' => ':memory:',
			]);

			return new DatabaseAdapter($connection);
		}
	}
