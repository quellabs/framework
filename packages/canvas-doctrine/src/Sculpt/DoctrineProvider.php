<?php

	namespace Quellabs\CanvasDoctrine\Sculpt;

	use Quellabs\CanvasDoctrine\Sculpt\Commands\DiffCommand;
	use Quellabs\CanvasDoctrine\Sculpt\Commands\GenerateCommand;
	use Quellabs\CanvasDoctrine\Sculpt\Commands\MigrateCommand;
	use Quellabs\CanvasDoctrine\Sculpt\Commands\StatusCommand;
	use Quellabs\Sculpt\Application;
	use Quellabs\Sculpt\ServiceProvider;

	/**
	 * Registers Doctrine Sculpt commands.
	 */
	class DoctrineProvider extends ServiceProvider {

		/**
		 * Registers the config publisher and migration commands.
		 * @param Application $application
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				PublishConfigCommand::class,
				MigrateCommand::class,
				StatusCommand::class,
				GenerateCommand::class,
				DiffCommand::class,
			]);
		}
	}
