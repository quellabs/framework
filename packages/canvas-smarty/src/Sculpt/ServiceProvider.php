<?php

	namespace Quellabs\Canvas\Smarty\Sculpt;

	use Quellabs\Canvas\Smarty\ServiceProvider as SmartyServiceProvider;
	use Quellabs\Sculpt\Application;
	use Quellabs\Sculpt\Contracts\CommandInterface;
	use Quellabs\Sculpt\Contracts\SculptProviderInterface;

	/**
	 * Service Provider for Smarty template engine integration
	 * Registers Smarty-related commands with the Sculpt application.
	 *
	 * Extends the "di" category ServiceProvider (rather than the generic
	 * Quellabs\Sculpt\ServiceProvider) so this provider is itself a valid
	 * SmartyServiceProvider — letting commands like ClearCacheCommand use the
	 * injected provider directly for mergeConfig() instead of resolving a
	 * separate instance.
	 */
	class ServiceProvider extends SmartyServiceProvider implements SculptProviderInterface {

		/**
		 * Register services and commands with the application
		 * This method is called during the application bootstrap process
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			// Register all Smarty-related commands with the application
			// This makes the commands available through the CLI interface
			$this->registerCommands($application, [
				InitCommand::class,        // Publish the config file
				ClearCacheCommand::class,  // Register the smarty:clear_cache command
			]);
		}

		/**
		 * Helper method to register multiple commands at once
		 * @param Application $app The application instance
		 * @param list<class-string<CommandInterface>> $commands Array of command class names to register
		 */
		private function registerCommands(Application $app, array $commands): void {
			foreach ($commands as $command) {
				$instance = new $command($app->getInput(), $app->getOutput(), $this);

				if ($instance instanceof CommandInterface) {
					$app->registerCommand($instance);
				}
			}
		}
	}
