<?php
	
	namespace Quellabs\Canvas\Smarty\Sculpt;
	
	use Smarty\Smarty;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Command class for clearing Smarty template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {

		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "smarty:clear-cache";
		}

		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Clears the smarty cache";
		}

		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// This command only needs three directory paths, each with a
			// default matching Smarty's own ServiceProvider::getDefaults() —
			// duplicated here rather than referencing that provider, since
			// clearing a cache is unrelated to resolving TemplateEngineInterface
			// for the DI container, which is that provider's actual job.
			$configuration = $this->loadMergedConfig(['config/smarty.php', 'config/app.php']);
			$projectRoot = ComposerUtils::getProjectRoot();

			$templateDir = is_string($configuration['template_dir'] ?? null) ? $configuration['template_dir'] : $projectRoot . '/templates/';
			$compileDir = is_string($configuration['compile_dir'] ?? null) ? $configuration['compile_dir'] : $projectRoot . '/storage/smarty/compile/';
			$cacheDir = is_string($configuration['cache_dir'] ?? null) ? $configuration['cache_dir'] : $projectRoot . '/storage/smarty/cache/';

			// Create Smarty instance with configured directories
			$smarty = new Smarty();
			$smarty->setTemplateDir($templateDir);
			$smarty->setCompileDir($compileDir);
			$smarty->setCacheDir($cacheDir);

			// Clear all cached templates and compiled files
			$smarty->clearAllCache();

			// Display a success message to the user
			$this->getOutput()->success("Cleared the smarty cache");

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