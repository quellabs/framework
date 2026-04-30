<?php
	
	namespace Quellabs\Canvas\Latte\Sculpt;
	
	use Quellabs\Canvas\Latte\ServiceProvider;
	use Quellabs\Canvas\Latte\LatteTemplate;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Command class for clearing Latte template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {
		
		/** @var ServiceProvider|null */
		protected ?ProviderInterface $provider;
		
		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "latte:clear-cache";
		}
		
		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Clears the latte cache";
		}
		
		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// Get default configuration values directly from the provider class
			$defaults = ServiceProvider::getDefaults();
			
			// Get current configuration from the provider instance
			$configuration = $this->provider !== null ? $this->provider->getConfig() : [];
			
			// Create Latte instance with configured directories
			$latte = new LatteTemplate(array_merge($defaults, $configuration));
			
			// Clear all cached templates and compiled files
			$latte->clearCache();
			
			// Display a success message to the user
			$this->getOutput()->success("Cleared the latte cache");
			
			// Return success exit code
			return 0;
		}
	}