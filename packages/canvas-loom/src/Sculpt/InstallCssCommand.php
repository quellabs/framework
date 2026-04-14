<?php
	
	namespace Quellabs\Canvas\Loom\Sculpt;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * Installs the default Loom CSS file into the project's public directory.
	 * Extends the base command contract to provide asset publishing functionality.
	 */
	class InstallCssCommand extends CommandBase {
		
		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "loom:install-css";
		}
		
		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Copies the default Loom CSS to the public directory";
		}
		
		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			$publicDirectory = $config->get("public_directory");
			
			if (empty($publicDirectory)) {
				$this->output->error("Configuration key 'public_directory' is missing or empty.");
				return 1;
			}
			
			$projectRoot    = ComposerUtils::getProjectRoot();
			$targetDir      = $projectRoot . DIRECTORY_SEPARATOR . $publicDirectory;
			$targetFile     = $targetDir . DIRECTORY_SEPARATOR . "loom.css";
			$sourceFile     = __DIR__ . "/../../resources/loom.css";
			
			if (!file_exists($sourceFile)) {
				$this->output->error("Source file not found: {$sourceFile}");
				return 1;
			}
			
			if (!is_dir($targetDir)) {
				$this->output->error("Public directory does not exist: {$targetDir}");
				return 1;
			}
			
			if (!is_writable($targetDir)) {
				$this->output->error("Public directory is not writable: {$targetDir}");
				return 1;
			}
			
			if (!copy($sourceFile, $targetFile)) {
				$this->output->error("Failed to copy loom.css to {$targetFile}");
				return 1;
			}
			
			$this->output->success("Copied loom.css to {$targetFile}");
			return 0;
		}
	}