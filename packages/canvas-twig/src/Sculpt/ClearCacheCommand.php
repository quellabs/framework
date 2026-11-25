<?php

	namespace Quellabs\Canvas\Twig\Sculpt;

	use Quellabs\Canvas\Twig\TwigTemplate;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Command class for clearing Twig template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {

		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "twig:clear-cache";
		}

		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Clears the twig cache";
		}

		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// TwigTemplate::clearCache() only operates when caching was enabled
			// and a cache_dir was set at construction time; 'template_dir' is
			// also read unconditionally in the constructor. Defaults below match
			// Twig's own ServiceProvider::getDefaults(), duplicated here rather
			// than referencing that provider, since clearing a cache is unrelated
			// to resolving TemplateEngineInterface for the DI container, which is
			// that provider's actual job.
			$configuration = $this->loadMergedConfig(['config/twig.php', 'config/app.php']);
			$projectRoot = ComposerUtils::getProjectRoot();

			$twig = new TwigTemplate([
				'template_dir' => is_string($configuration['template_dir'] ?? null) ? $configuration['template_dir'] : $projectRoot . '/templates/',
				'cache_dir'    => is_string($configuration['cache_dir'] ?? null) ? $configuration['cache_dir'] : $projectRoot . '/storage/cache/twig/',
				'caching'      => is_bool($configuration['caching'] ?? null) ? $configuration['caching'] : true,
			]);

			// Clear all cached templates and compiled files
			$twig->clearCache();

			// Display a success message to the user
			$this->getOutput()->success("Cleared the twig cache");

			// Return success exit code
			return 0;
		}
	}
