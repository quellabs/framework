<?php

	namespace Quellabs\Support;

	/**
	 * Determines which ORM(s) a Canvas project has installed, so packages that
	 * ship ORM-specific code (e.g. scaffolded entities or stubs) can pick the
	 * matching variant instead of assuming one ORM, and so a package can tell
	 * whether it is the only ORM present (safe to register a short, unprefixed
	 * Sculpt command) or sharing the project with another one (must register
	 * under its own prefix to avoid a command-name collision).
	 *
	 * Detection reads the project's own config/discovery-mapping.php — the file
	 * Discover generates from every installed package's composer.json "extra"
	 * block — rather than a config value, because no config key names the
	 * active ORM the way config/app.php's template_engine does.
	 */
	class OrmEngineResolver {

		/**
		 * Composer package => ORM identifier, checked in this order. A project
		 * can have more than one of these installed at once; installed() and
		 * count() report all of them, while resolve() picks the first match.
		 */
		private const array ORM_PACKAGES = [
			'quellabs/canvas-doctrine' => 'doctrine',
			'quellabs/canvas-eloquent' => 'eloquent',
			'quellabs/objectquel'      => 'objectquel',
		];

		/**
		 * Resolve the single "best" ORM identifier ('doctrine', 'eloquent', or
		 * 'objectquel') for the current project. When more than one is
		 * installed, ORM_PACKAGES order is the tie-break. ObjectQuel is the
		 * fallback when none are discovered (e.g. Discover hasn't run yet).
		 * @param string|null $startDirectory Directory to start the project-root search from (defaults to the current directory)
		 * @return string
		 */
		public static function resolve(?string $startDirectory = null): string {
			return self::installed($startDirectory)[0] ?? 'objectquel';
		}

		/**
		 * Returns every ORM identifier discovered for the current project, in
		 * ORM_PACKAGES order. Empty when no mapping file exists yet or none of
		 * the known ORM packages are discovered in it.
		 * @param string|null $startDirectory Directory to start the project-root search from (defaults to the current directory)
		 * @return list<string>
		 */
		public static function installed(?string $startDirectory = null): array {
			$mapping = self::readMapping($startDirectory);

			if ($mapping === null) {
				return [];
			}

			$installed = [];

			foreach (self::ORM_PACKAGES as $package => $orm) {
				if (isset($mapping[$package])) {
					$installed[] = $orm;
				}
			}

			return $installed;
		}

		/**
		 * Returns how many of the known ORM packages are installed together.
		 * A Sculpt command can use this to decide whether a short, unprefixed
		 * signature is safe (count() === 1) or would risk colliding with
		 * another installed ORM's command of the same name (count() > 1).
		 * @param string|null $startDirectory Directory to start the project-root search from (defaults to the current directory)
		 * @return int
		 */
		public static function count(?string $startDirectory = null): int {
			return count(self::installed($startDirectory));
		}

		/**
		 * Loads the project's discovery mapping array, or null when there is
		 * none to read (no mapping file yet, or its contents are malformed).
		 * @param string|null $startDirectory
		 * @return array<mixed, mixed>|null
		 */
		private static function readMapping(?string $startDirectory): ?array {
			$mappingPath = ComposerUtils::getDiscoveryMappingPath($startDirectory);

			if ($mappingPath === null || !file_exists($mappingPath)) {
				return null;
			}

			$mapping = require $mappingPath;

			if (!is_array($mapping)) {
				return null;
			}

			return $mapping;
		}
	}
