<?php

	namespace Quellabs\Sculpt;

	use Quellabs\Sculpt\Contracts\CommandInterface;

	/**
	 * Provides the command-registration helper SculptProviderInterface
	 * implementers use inside their register() method. Extracted into a trait
	 * (rather than living only on the ServiceProvider base class) so a
	 * package's "di" category provider can use it directly when its "sculpt"
	 * category provider extends that di provider instead of ServiceProvider.
	 */
	trait RegistersCommandsTrait {

		/**
		 * Helper method to register multiple commands at once
		 * @param Application $app The application instance
		 * @param list<class-string<CommandInterface>> $commands Array of command class names to register
		 */
		protected function registerCommands(Application $app, array $commands): void {
			foreach ($commands as $command) {
				// Instantiate the command class and register it with the application
				$instance = new $command($app->getInput(), $app->getOutput(), $this);

				if ($instance instanceof CommandInterface) {
					$app->registerCommand($instance);
				}
			}
		}
	}
