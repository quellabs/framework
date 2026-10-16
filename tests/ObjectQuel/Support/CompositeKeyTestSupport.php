<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;

	/**
	 * Shared setup for the composite-primary-key metadata test suite. Pure
	 * annotation/reflection work — touches no database at all.
	 */
	trait CompositeKeyTestSupport {

		/**
		 * require_once every fixture entity file once per process, mirroring
		 * FkTestSupport::loadFkFixtureEntities() — these fixtures are never
		 * referenced by name from test code, so nothing would trigger
		 * autoloading of them otherwise.
		 */
		protected static function loadCompositeKeyFixtureEntities(): void {
			static $loaded = false;

			if ($loaded) {
				return;
			}

			foreach (glob(__DIR__ . '/../Fixtures/CompositeKeyEntities/*.php') ?: [] as $file) {
				require_once $file;
			}

			$loaded = true;
		}

		/**
		 * Builds an EntityStore scoped to the composite-key fixture entities.
		 * Pure annotation/reflection work — touches no database at all.
		 */
		protected function makeCompositeKeyEntityStore(): EntityStore {
			self::loadCompositeKeyFixtureEntities();

			$configuration = new Configuration();
			$configuration->setEntityPath(__DIR__ . '/../Fixtures/CompositeKeyEntities');
			$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyEntities');
			$configuration->setUseMetadataCache(false);

			return new EntityStore($configuration);
		}
	}
