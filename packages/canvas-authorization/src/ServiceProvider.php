<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Sculpt\Application;
	
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register the commands into the Sculpt application
		 * @param Application $application
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				MakeAuthCommand::class,
				MakeAuthAspectCommand::class
			]);
		}
	}