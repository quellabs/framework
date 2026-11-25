<?php

	namespace Quellabs\Sculpt\Contracts;

	use Quellabs\Sculpt\Application;

	/**
	 * Implemented by any provider class Application should register commands
	 * from. Kept separate from the concrete ServiceProvider base class so a
	 * package's "di" category provider can implement this directly — letting
	 * its "sculpt" category provider extend it and inherit its config/methods,
	 * rather than being forced to extend the unrelated ServiceProvider base.
	 */
	interface SculptProviderInterface {

		/**
		 * Register this provider's commands with the Sculpt application.
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void;
	}
