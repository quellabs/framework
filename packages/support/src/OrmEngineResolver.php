<?php

	namespace Quellabs\Support;

	/**
	 * Determines which ORM a Canvas project has installed, so packages that
	 * ship ORM-specific code (e.g. scaffolded entities or stubs) can pick the
	 * matching variant instead of assuming one ORM.
	 *
	 * Detection reads the project's own config/discovery-mapping.php — the file
	 * Discover generates from every installed package's composer.json "extra"
	 * block — rather than a config value, because no config key names the
	 * active ORM the way config/app.php's template_engine does.
	 */
	class OrmEngineResolver {

		/**
		 * Canvas integration package => ORM identifier, checked in this order.
		 * ObjectQuel is the fallback when none of these are discovered.
		 */
		private const array ORM_PACKAGES = [
			'quellabs/canvas-doctrine' => 'doctrine',
			'quellabs/canvas-eloquent' => 'eloquent',
		];

		/**
		 * Resolve the ORM identifier ('doctrine', 'eloquent', or 'objectquel')
		 * for the current project.
		 * @param string|null $startDirectory Directory to start the project-root search from (defaults to the current directory)
		 * @return string
		 */
		public static function resolve(?string $startDirectory = null): string {
			$mappingPath = ComposerUtils::getDiscoveryMappingPath($startDirectory);

			// No mapping file yet (e.g. Discover hasn't run) — can't detect anything
			if ($mappingPath === null || !file_exists($mappingPath)) {
				return 'objectquel';
			}

			$mapping = require $mappingPath;

			// Malformed or emptied mapping file — same as not having one
			if (!is_array($mapping)) {
				return 'objectquel';
			}

			// First match wins; order in ORM_PACKAGES is the tie-break if both are installed
			foreach (self::ORM_PACKAGES as $package => $orm) {
				if (isset($mapping[$package])) {
					return $orm;
				}
			}

			// Neither Doctrine nor Eloquent discovered — ObjectQuel is the default
			return 'objectquel';
		}
	}
