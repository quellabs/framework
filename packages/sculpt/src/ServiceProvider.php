<?php
	
	namespace Quellabs\Sculpt;
	
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Sculpt\Contracts\CommandInterface;
	
	/**
	 * Base implementation of the ServiceProviderInterface that provides
	 * common functionality for service providers in the Sculpt framework.
	 */
	abstract class ServiceProvider extends AbstractProvider {
		
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
		
		/**
		 * Register the service provider with the Sculpt application.
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		abstract public function register(Application $application): void;
	}