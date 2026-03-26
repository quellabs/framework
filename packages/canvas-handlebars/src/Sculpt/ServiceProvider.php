<?php
	
	namespace Quellabs\Canvas\Handlebars\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
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
				ClearCacheCommand::class,
			]);
		}
	}