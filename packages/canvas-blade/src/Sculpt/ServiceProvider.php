<?php
	
	namespace Quellabs\Canvas\Blade\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
	/**
	 * Service Provider for Blade template engine integration
	 * Registers Blade-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * This method is called during the application bootstrap process
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			// Register all Blade-related commands with the application
			// This makes the commands available through the CLI interface
			$this->registerCommands($application, [
				ClearCacheCommand::class,  // Register the blade:clear-cache command
			]);
		}
	}