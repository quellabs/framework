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
			// LatteTemplate::clearCache() only reads 'cache_dir', but its
			// constructor's declared type requires the full shape, and reads
			// 'template_dir'/'caching' unconditionally. Defaults below match
			// Latte's own ServiceProvider::getDefaults(), duplicated here rather
			// than referencing that provider, since clearing a cache is unrelated
			// to resolving TemplateEngineInterface for the DI container, which is
			// that provider's actual job. filters/functions/extensions/paths/
			// globals are irrelevant to clearing the cache, so they're left empty
			// rather than read from config.
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

		/**
		 * Loads config files relative to the project root and merges them in
		 * order, applying each file's own .local.php override when present.
		 * @param array<int, string> $configFiles
		 * @return array<string, mixed>
		 */
		private function loadMergedConfig(array $configFiles): array {
			$projectRoot = ComposerUtils::getProjectRoot();
			$result = [];

			foreach ($configFiles as $configFile) {
				$path = $projectRoot . DIRECTORY_SEPARATOR . $configFile;

				if (file_exists($path)) {
					$result = array_replace_recursive($result, require $path);
				}

				$localPath = preg_replace('/\.php$/', '.local.php', $path);

				if ($localPath !== null && file_exists($localPath)) {
					$result = array_replace_recursive($result, require $localPath);
				}
			}

			return $result;
		}
	}
