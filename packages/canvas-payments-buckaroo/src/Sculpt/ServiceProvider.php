<?php
	
	namespace Quellabs\Payments\Buckaroo\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
	/**
	 * Service Provider for Buckaroo payment engine integration
	 * Registers Buckaroo-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * This method is called during the application bootstrap process
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			// Register all Buckaroo-related commands with the application
			// This makes the commands available through the CLI interface
			$this->registerCommands($application, [
				InitCommand::class
			]);
		}
	}