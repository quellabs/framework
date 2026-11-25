<?php
	
	namespace Quellabs\Sculpt;
	
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Sculpt\Contracts\CommandInterface;
	use Quellabs\Sculpt\Contracts\SculptProviderInterface;

	/**
	 * Base implementation of the ServiceProviderInterface that provides
	 * common functionality for service providers in the Sculpt framework.
	 *
	 * A package whose "sculpt" category provider needs to extend its own "di"
	 * category provider instead (to inherit that provider's configuration and
	 * methods) implements SculptProviderInterface directly rather than
	 * extending this class — see SculptProviderInterface.
	 */
	abstract class ServiceProvider extends AbstractProvider implements SculptProviderInterface {

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