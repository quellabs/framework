<?php
	
	namespace Quellabs\Canvas\Plates\Sculpt;
	
	use Quellabs\Sculpt\Application;
	use Quellabs\Canvas\Latte\Sculpt\InitCommand;
	use Quellabs\Canvas\Latte\Sculpt\ClearCacheCommand;
	
	/**
	 * Service Provider for Plates template engine integration
	 * Registers Plates-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				InitCommand::class
			]);
		}
	}