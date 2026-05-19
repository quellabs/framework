<?php
	
	namespace Quellabs\Canvas\Latte\Sculpt;
	
	use Quellabs\Canvas\Latte\LatteTemplate;
	use Quellabs\Canvas\Latte\ServiceProvider;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Command class for clearing Latte template cache
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
			// Validate that the provider is of the correct type
			if (!$this->provider instanceof ServiceProvider) {
				throw new \RuntimeException('Expected ' . ServiceProvider::class);
			}
			
			// Create Latte instance with merged configuration and clear cache
			$latte = new LatteTemplate($this->provider->mergeConfig());
			$latte->clearCache();
			
			// Display a success message to the user
			$this->getOutput()->success("Cleared the latte cache");
			
			// Return success exit code
			return 0;
		}
	}