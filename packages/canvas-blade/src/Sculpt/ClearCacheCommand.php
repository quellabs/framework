<?php

	namespace Quellabs\Canvas\Blade\Sculpt;

	use Quellabs\Canvas\Blade\BladeTemplate;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Command class for clearing Blade template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {

		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "blade:clear-cache";
		}

		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Clears the blade cache";
		}

		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// getProvider() is already configured from this package's own
			// composer.json "sculpt" discovery entry (config/blade.php), so
			// it's already the settings a user published — no need to reload
			// anything from disk. Only cache_dir matters to clearCache(); the
			// rest just satisfies BladeTemplate's declared config shape.
			$configuration = $this->getProvider()->getConfig();
			$projectRoot = ComposerUtils::getProjectRoot();

			$blade = new BladeTemplate([
				'template_dir'  => is_string($configuration['template_dir'] ?? null) ? $configuration['template_dir'] : $projectRoot . '/templates/',
				'cache_dir'     => is_string($configuration['cache_dir'] ?? null) ? $configuration['cache_dir'] : $projectRoot . '/storage/cache/blade/',
				'caching'       => is_bool($configuration['caching'] ?? null) ? $configuration['caching'] : true,
				'paths'         => [],
				'directives'    => [],
				'if_directives' => [],
				'globals'       => [],
			]);

			// Clear all cached templates and compiled files
			$blade->clearCache();

			// Display a success message to the user
			$this->getOutput()->success("Cleared the blade cache");

			// Return success exit code
			return 0;
		}
	}
