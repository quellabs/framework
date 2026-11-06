<?php

	namespace Quellabs\CanvasAuthorization\Eloquent;

	use Quellabs\CanvasAuthorization\Eloquent\Sculpt\InstallAuthCommand;
	use Quellabs\CanvasAuthorization\Eloquent\Sculpt\MakeAuthAspectCommand;
	use Quellabs\Sculpt\Application;

	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {

		/**
		 * Register the commands into the Sculpt application
		 * @param Application $application
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				InstallAuthCommand::class,
				MakeAuthAspectCommand::class
			]);
		}
	}
