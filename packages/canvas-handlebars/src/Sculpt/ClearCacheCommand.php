<?php
	
	namespace Quellabs\Canvas\Handlebars\Sculpt;
	
	use Quellabs\Canvas\Handlebars\ServiceProvider;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Command class for clearing compiled Handlebars templates.
	 *
	 * Unlike output-cache engines, LightnCandy's cache consists of compiled PHP files.
	 * This command removes them so templates are recompiled on next request.
	 */
	class ClearCacheCommand extends CommandBase {

		/**
		 * Define the command signature used to invoke this command
		 * @return string
		 */
		public function getSignature(): string {
			return "handlebars:clear-cache";
		}
		
		/**
		 * Human-readable description shown in the Sculpt command list
		 * @return string
		 */
		public function getDescription(): string {
			return "Clears the Handlebars compiled template cache";
		}
		
		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			// $this->provider is the "sculpt" category provider (registered
			// separately in composer.json's extra.discover.sculpt key) and has
			// no mergeConfig() — it is a different class from the "di" category
			// provider this command actually needs. Build the latter directly,
			// loading the same config files its discovery entry declares.
			$provider = new ServiceProvider();
			$provider->setConfig($this->loadMergedConfig(['config/handlebars.php', 'config/app.php']));

			// Fetch compile directory
			$compileDir = $provider->mergeConfig()['compile_dir'];
			
			if (!is_dir($compileDir)) {
				$this->getOutput()->warning("Compile directory does not exist: {$compileDir}");
				return 0;
			}
			
			// Unlink php files
			$files = glob($compileDir . '*.php') ?: [];
			$count = 0;
			$failed = 0;
			
			foreach ($files as $file) {
				if (is_file($file)) {
					if (unlink($file)) {
						$count++;
					} else {
						$failed++;
						$this->getOutput()->error("Failed to delete: {$file}");
					}
				}
			}
			
			// Error message
			if ($failed > 0) {
				$this->getOutput()->error("Cleared {$count} compiled template(s), {$failed} could not be deleted");
				return 1;
			}
			
			// Success message
			$this->getOutput()->success("Cleared {$count} compiled Handlebars template(s)");
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