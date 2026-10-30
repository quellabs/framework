<?php

	namespace Quellabs\CanvasEloquent\Sculpt;

	use Quellabs\Sculpt\Application;
	use Quellabs\Sculpt\ServiceProvider;

	/**
	 * Registers quellabs/canvas-eloquent commands with the Sculpt CLI.
	 * Sculpt discovers this provider automatically via the "discover"
	 * section in composer.json.
	 */
	class EloquentProvider extends ServiceProvider {

		/**
		 * Register all canvas-eloquent commands with the Sculpt application.
		 * @param Application $application The application the provider registers with
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				PublishConfigCommand::class,
			]);
		}
	}
