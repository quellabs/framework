<?php
	
	namespace Quellabs\Shipments\DHL\Sculpt;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Publishes the DHL configuration file to the project's config directory.
	 * Skips the operation if the file already exists.
	 */
	class InitCommand extends CommandBase {
		
		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "dhl:init";
		}
		
		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Publishes the DHL config file to config/dhl.php";
		}
		
		/**
		 * Copy the default DHL config file to the project's config directory.
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			$source = dirname(__FILE__) . "/../../config/dhl.php";
			$target = ComposerUtils::getProjectRoot() . "/config/dhl.php";
			
			// Skip if the config file was already published
			if (file_exists($target)) {
				$this->getOutput()->success("Config file already exists, skipping");
				return 0;
			}
			
			// Copy the default config to the project root
			$result = copy($source, $target);
			
			if ($result) {
				$this->getOutput()->success("Published config/dhl.php");
			} else {
				$this->getOutput()->error("Failed to copy config file to {$target}");
			}
			
			return $result ? 0 : 1;
		}
	}