<?php
	
	namespace Quellabs\Canvas\Latte\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
	/**
	 * Service Provider for Latte template engine integration
	 * Registers Latte-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * This method is called during the application bootstrap process
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			// Register all Latte-related commands with the application
			// This makes the commands available through the CLI interface
			$this->registerCommands($application, [
				ClearCacheCommand::class,  // Register the latte:clear-cache command
			]);
		}
	}