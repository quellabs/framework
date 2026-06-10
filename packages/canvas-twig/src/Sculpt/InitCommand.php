<?php
	
	namespace Quellabs\Canvas\Twig\Sculpt;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Publishes the Twig configuration file to the project's config directory.
	 * Skips the operation if the file already exists.
	 */
	class InitCommand extends CommandBase {
		
		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "twig:init";
		}
		
		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Publishes the Twig config file to config/twig.php";
		}
		
		/**
		 * Copy the default Twig config file to the project's config directory.
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			$source = dirname(__FILE__) . "/../../config/twig.php";
			$target = ComposerUtils::getProjectRoot() . "/config/twig.php";
			
			// Skip if the config file was already published
			if (file_exists($target)) {
				$this->getOutput()->success("Config file already exists, skipping");
				return 0;
			}
			
			// Copy the default config to the project root
			$result = copy($source, $target);
			
			if ($result) {
				$this->getOutput()->success("Published config/twig.php");
			} else {
				$this->getOutput()->error("Failed to copy config file to {$target}");
			}
			
			return $result ? 0 : 1;
		}
	}