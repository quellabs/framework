<?php
	
	namespace Quellabs\Canvas\Plates\Sculpt;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Publishes the Plates configuration file to the project's config directory.
	 * Skips the operation if the file already exists.
	 */
	class InitCommand extends CommandBase {
		
		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "plates:init";
		}
		
		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Publishes the Plates config file to config/plates.php";
		}
		
		/**
		 * Copy the default Plates config file to the project's config directory.
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			$source = dirname(__FILE__) . "/../../config/plates.php";
			$target = ComposerUtils::getProjectRoot() . "/config/plates.php";
			
			// Skip if the config file was already published
			if (file_exists($target)) {
				$this->getOutput()->success("Config file already exists, skipping");
				return 0;
			}
			
			// Copy the default config to the project root
			$result = copy($source, $target);
			
			if ($result) {
				$this->getOutput()->success("Published config/plates.php");
			} else {
				$this->getOutput()->error("Failed to copy config file to {$target}");
			}
			
			return $result ? 0 : 1;
		}
	}