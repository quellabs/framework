<?php
	
	namespace Quellabs\Canvas\Smarty\Sculpt;
	
	use Smarty\Smarty;
	use Quellabs\Canvas\Smarty\ServiceProvider;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Command class for clearing Smarty template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {
		
		/** @var ServiceProvider */
		protected ProviderInterface $provider;
		
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
			// Validate that the provider is of the correct type
			if (!$this->provider instanceof ServiceProvider) {
				throw new \RuntimeException('Expected ' . ServiceProvider::class);
			}
			
			// Fetch config
			$mergedConfig = $this->provider->mergeConfig();
			
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
	}