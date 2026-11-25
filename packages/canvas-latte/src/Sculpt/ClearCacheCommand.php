<?php

	namespace Quellabs\Canvas\Latte\Sculpt;

	use Quellabs\Canvas\Latte\LatteTemplate;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Command class for clearing Latte template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {

		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "latte:clear-cache";
		}

		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Clears the latte cache";
		}

		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// clearCache() reads cache_dir; the constructor also needs
			// template_dir/caching unconditionally. Defaults mirror Latte's
			// ServiceProvider::getDefaults() without importing it.
			$configuration = $this->loadMergedConfig(['config/latte.php', 'config/app.php']);
			$projectRoot = ComposerUtils::getProjectRoot();

			$latte = new LatteTemplate([
				'template_dir' => is_string($configuration['template_dir'] ?? null) ? $configuration['template_dir'] : $projectRoot . '/templates/',
				'cache_dir'    => is_string($configuration['cache_dir'] ?? null) ? $configuration['cache_dir'] : $projectRoot . '/storage/cache/latte/',
				'caching'      => is_bool($configuration['caching'] ?? null) ? $configuration['caching'] : true,
				'filters'      => [],
				'functions'    => [],
				'extensions'   => [],
				'paths'        => [],
				'globals'      => [],
			]);

			$latte->clearCache();

			// Display a success message to the user
			$this->getOutput()->success("Cleared the latte cache");

			// Return success exit code
			return 0;
		}
	}
