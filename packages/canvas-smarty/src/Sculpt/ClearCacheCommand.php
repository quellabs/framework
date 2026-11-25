<?php
	
	namespace Quellabs\Canvas\Smarty\Sculpt;
	
	use Smarty\Smarty;
	use Quellabs\Canvas\Smarty\ServiceProvider;
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
			// $this->provider is the "sculpt" category provider (registered
			// separately in composer.json's extra.discover.sculpt key) and has
			// no mergeConfig() — it is a different class from the "di" category
			// provider this command actually needs. Build the latter directly,
			// loading the same config files its discovery entry declares.
			$provider = new ServiceProvider();
			$provider->setConfig($this->loadMergedConfig(['config/smarty.php', 'config/app.php']));

			// Fetch config
			$mergedConfig = $provider->mergeConfig();

			// Create Smarty instance with configured directories
			$smarty = new Smarty();
			$smarty->setTemplateDir($mergedConfig['template_dir']);
			$smarty->setCompileDir($mergedConfig['compile_dir']);
			$smarty->setCacheDir($mergedConfig['cache_dir']);

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