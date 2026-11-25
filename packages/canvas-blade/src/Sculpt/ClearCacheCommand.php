<?php
	
	namespace Quellabs\Canvas\Blade\Sculpt;
	
	use Quellabs\Canvas\Blade\BladeTemplate;
	use Quellabs\Canvas\Blade\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Command class for clearing Blade template cache
	 * Extends the base command contract to provide cache clearing functionality
	 */
	class ClearCacheCommand extends CommandBase {

		/**
		 * Define the command signature/name that will be used to invoke this command
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "blade:clear-cache";
		}

		/**
		 * Provide a human-readable description of what this command does
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Clears the blade cache";
		}

		/**
		 * Execute the cache clearing operation
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// $this->provider (see CommandBase::getProvider()) is the "sculpt"
			// category provider, not the "di" category one this command needs
			// for the actual Blade configuration — resolve that one instead.
			$provider = $this->resolveDiProvider(ServiceProvider::class)
				?? throw new \RuntimeException('Could not resolve ' . ServiceProvider::class . ' via discovery');

			// Create Blade instance with configured directories
			$blade = new BladeTemplate($provider->mergeConfig());

			// Clear all cached templates and compiled files
			$blade->clearCache();

			// Display a success message to the user
			$this->getOutput()->success("Cleared the blade cache");

			// Return success exit code
			return 0;
		}
	}