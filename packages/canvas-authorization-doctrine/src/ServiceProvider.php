<?php

	namespace Quellabs\CanvasAuthorization\Doctrine;

	use Quellabs\CanvasAuthorization\Doctrine\Sculpt\InstallAuthCommand;
	use Quellabs\CanvasAuthorization\Doctrine\Sculpt\MakeAuthAspectCommand;
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
