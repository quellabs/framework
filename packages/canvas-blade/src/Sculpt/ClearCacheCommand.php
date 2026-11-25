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
			// BladeTemplate::clearCache() only reads 'cache_dir', but its
			// constructor's declared type requires the full shape. Defaults
			// below match Blade's own ServiceProvider::getDefaults(), duplicated
			// here rather than referencing that provider, since clearing a cache
			// is unrelated to resolving TemplateEngineInterface for the DI
			// container, which is that provider's actual job.
			$configuration = $this->loadMergedConfig(['config/blade.php', 'config/app.php']);
			$projectRoot = ComposerUtils::getProjectRoot();

			// Create Blade instance with the directories that matter for clearing
			// its cache. paths/directives/if_directives/globals are irrelevant to
			// that operation, so they're left empty rather than read from config.
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
