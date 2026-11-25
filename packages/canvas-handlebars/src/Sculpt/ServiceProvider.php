<?php
	
	namespace Quellabs\Canvas\Handlebars\Sculpt;
	
	use Quellabs\Sculpt\Application;
	use Quellabs\Canvas\Handlebars\Sculpt\InitCommand;
	use Quellabs\Canvas\Handlebars\Sculpt\ClearCacheCommand;
	
	/**
	 * Service Provider for Handlebars template engine integration
	 * Registers Handlebars-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				InitCommand::class,        // Publish the config file
				ClearCacheCommand::class,  // Register the handlebars:clear-cache command
			]);
		}
	}