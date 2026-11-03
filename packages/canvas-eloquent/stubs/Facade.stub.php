<?php

	/**
	 * Minimal PHPStan stub for Illuminate\Support\Facades\Facade, covering only
	 * the $app-related members canvas-eloquent's MigratorFactory actually calls.
	 *
	 * illuminate/support declares $app as \Illuminate\Contracts\Foundation\Application|null,
	 * but Facade's real implementation only ever calls base Container contract
	 * methods on it (make()/bound()/afterResolving()/array access) -- never
	 * anything Application-specific. This stub widens the declared type to
	 * object|null, which is what Facade genuinely accepts at runtime and what
	 * MigratorFactory passes (Capsule's plain Container) when wiring the
	 * Schema:: facade for standalone-Capsule migrations. A vendor interface
	 * like Container can't be named here instead: PHPStan's stub validator
	 * resolves class references against the configured analysis paths only,
	 * not the live autoloader, and no analysed path declares it.
	 */

	namespace Illuminate\Support\Facades;

	abstract class Facade {
		/**
		 * The application instance being facaded.
		 * @var object|null
		 */
		protected static $app;

		/**
		 * Get the application instance behind the facade.
		 * @return object|null
		 */
		public static function getFacadeApplication() {
		}

		/**
		 * Set the application instance.
		 * @param object|null $app
		 * @return void
		 */
		public static function setFacadeApplication($app) {
		}
	}
