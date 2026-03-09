<?php
	
	namespace Quellabs\Canvas\Smarty\Sculpt;
	
	use Smarty\Smarty;
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
			// Get default configuration values from the provider
			$defaults = $this->getProvider()::getDefaults();
			
			// Get current configuration from the provider
			$configuration = $this->getProvider()->getConfig();
			
			// Create Smarty instance with configured directories
			$smarty = new Smarty();
			
			// Set template directory (use configured value or fall back to default)
			$smarty->setTemplateDir($configuration['template_dir'] ?? $defaults['template_dir']);
			
			// Set compile directory (use configured value or fall back to default)
			$smarty->setCompileDir($configuration['compile_dir'] ?? $defaults['compile_dir']);
			
			// Set cache directory (use configured value or fall back to default)
			$smarty->setCacheDir($configuration['cache_dir'] ?? $defaults['cache_dir']);
			
			// Clear all cached templates and compiled files
			$smarty->clearAllCache();
			
			// Display a success message to the user
			$this->getOutput()->success("Cleared the smarty cache");
			
			// Return success exit code
			return 0;
		}
	}