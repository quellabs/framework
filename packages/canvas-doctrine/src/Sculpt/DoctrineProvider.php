<?php

	namespace Quellabs\CanvasDoctrine\Sculpt;

	use Quellabs\Sculpt\Application;
	use Quellabs\Sculpt\ServiceProvider;

	/**
	 * Registers Doctrine Sculpt commands.
	 */
	class DoctrineProvider extends ServiceProvider {
		
		/**
		 * Registers the config publisher.
		 * @param Application $application
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [PublishConfigCommand::class]);
		}
	}
