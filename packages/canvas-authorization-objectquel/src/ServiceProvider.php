<?php

	namespace Quellabs\CanvasAuthorization\ObjectQuel;

	use Quellabs\CanvasAuthorization\ObjectQuel\Sculpt\InstallAuthCommand;
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
			]);
		}
	}
