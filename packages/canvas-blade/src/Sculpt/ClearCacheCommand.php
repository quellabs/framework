<?php
	
	namespace Quellabs\Canvas\Blade\Sculpt;
	
	use Quellabs\Canvas\Blade\BladeTemplate;
	use Quellabs\Canvas\Blade\ServiceProvider;
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
			// $this->provider is the "sculpt" category provider (registered
			// separately in composer.json's extra.discover.sculpt key) and has
			// no mergeConfig() — it is a different class from the "di" category
			// provider this command actually needs. Build the latter directly,
			// loading the same config files its discovery entry declares.
			$provider = new ServiceProvider();
			$provider->setConfig($this->loadMergedConfig(['config/blade.php', 'config/app.php']));

			// Create Blade instance with configured directories
			$blade = new BladeTemplate($provider->mergeConfig());

			// Clear all cached templates and compiled files
			$blade->clearCache();

			// Display a success message to the user
			$this->getOutput()->success("Cleared the blade cache");

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