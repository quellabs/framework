<?php
	
	namespace Quellabs\Payments\PaypalExpress\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
	/**
	 * Service Provider for PaypalExpress payment engine integration
	 * Registers PaypalExpress-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * This method is called during the application bootstrap process
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			// Register all PaypalExpress-related commands with the application
			// This makes the commands available through the CLI interface
			$this->registerCommands($application, [
				InitCommand::class
			]);
		}
	}