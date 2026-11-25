<?php
	
	namespace Quellabs\Canvas\Handlebars\Sculpt;

	use Quellabs\Canvas\Handlebars\ServiceProvider as HandlebarsServiceProvider;
	use Quellabs\Sculpt\Application;
	use Quellabs\Canvas\Handlebars\Sculpt\InitCommand;
	use Quellabs\Canvas\Handlebars\Sculpt\ClearCacheCommand;
	use Quellabs\Sculpt\Contracts\SculptProviderInterface;
	use Quellabs\Sculpt\RegistersCommandsTrait;

	/**
	 * Service Provider for Handlebars template engine integration
	 * Registers Handlebars-related commands with the Sculpt application.
	 *
	 * Extends the "di" category ServiceProvider (rather than the generic
	 * Quellabs\Sculpt\ServiceProvider) so this provider is itself a valid
	 * HandlebarsServiceProvider — letting commands like ClearCacheCommand use
	 * the injected provider directly for mergeConfig() instead of resolving a
	 * separate instance.
	 */
	class ServiceProvider extends HandlebarsServiceProvider implements SculptProviderInterface {

		use RegistersCommandsTrait;

		/**
		 * Register services and commands with the application
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				InitCommand::class,        // Publish the config file
				ClearCacheCommand::class,  // Register the handlebars:clear-cache command
			]);
		}
	}