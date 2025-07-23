<?php
	
	namespace Quellabs\Canvas\Twig\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
	/**
	 * Service Provider for Twig template engine integration
	 * Registers Twig-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * This method is called during the application bootstrap process
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			// Register all Twig-related commands with the application
			// This makes the commands available through the CLI interface
			$this->registerCommands($application, [
				ClearCacheCommand::class,  // Register the twig:clear_cache command
			]);
		}
	}