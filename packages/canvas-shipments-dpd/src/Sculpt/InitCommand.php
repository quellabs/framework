<?php
	
	namespace Quellabs\Payments\DPD\Sculpt;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Publishes the DPD configuration file to the project's config directory.
	 * Skips the operation if the file already exists.
	 */
	class InitCommand extends CommandBase {
		
		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "dpd:init";
		}
		
		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Publishes the DPD config file to config/dpd.php";
		}
		
		/**
		 * Copy the default DPD config file to the project's config directory.
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			$source = dirname(__FILE__) . "/../../config/dpd.php";
			$target = ComposerUtils::getProjectRoot() . "/config/dpd.php";
			
			// Skip if the config file was already published
			if (file_exists($target)) {
				$this->getOutput()->success("Config file already exists, skipping");
				return 0;
			}
			
			// Copy the default config to the project root
			$result = copy($source, $target);
			
			if ($result) {
				$this->getOutput()->success("Published config/dpd.php");
			} else {
				$this->getOutput()->error("Failed to copy config file to {$target}");
			}
			
			return $result ? 0 : 1;
		}
	}