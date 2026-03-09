<?php
	
	namespace Quellabs\Canvas\Twig\Sculpt;
	
	use Quellabs\Canvas\Twig\TwigTemplate;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Command class for clearing Twig template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {
		
		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "twig:clear-cache";
		}
		
		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Clears the twig cache";
		}
		
		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// Get default configuration values from the provider
			$defaults = $this->getProvider()::getDefaults();
			
			// Get current configuration from the provider
			$configuration = $this->getProvider()->getConfig();
			
			// Create Twig instance with configured directories
			$twig = new TwigTemplate(array_merge($defaults, $configuration));
			
			// Clear all cached templates and compiled files
			$twig->clearCache();
			
			// Display a success message to the user
			$this->getOutput()->success("Cleared the twig cache");
			
			// Return success exit code
			return 0;
		}
	}