<?php
	
	namespace Quellabs\Canvas\Smarty\Sculpt;
	
	use Smarty\Smarty;
	use Quellabs\Canvas\Smarty\ServiceProvider;
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
			// $this->provider (see CommandBase::getProvider()) is the "sculpt"
			// category provider, not the "di" category one this command needs
			// for the actual Smarty configuration — resolve that one instead.
			$provider = $this->resolveDiProvider(ServiceProvider::class)
				?? throw new \RuntimeException('Could not resolve ' . ServiceProvider::class . ' via discovery');

			// Fetch config
			$mergedConfig = $provider->mergeConfig();

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