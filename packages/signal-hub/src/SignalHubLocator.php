<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Provides static access to a singleton SignalHub instance. This allows
	 * multiple parts of an application to share the same hub without explicit
	 * dependency injection, while still maintaining testability through the
	 * setInstance() method.
	 */
	class SignalHubLocator {

		/**
		 * The shared SignalHub instance
		 * @var SignalHub|null Null until first access via getInstance()
		 */
		private static ?SignalHub $instance = null;
		
		/**
		 * Get the shared SignalHub instance, creating it lazily if needed
		 * @return SignalHub The singleton hub instance
		 */
		public static function getInstance(): SignalHub {
			if (self::$instance === null) {
				self::$instance = new SignalHub();
			}
			
			return self::$instance;
		}
		
		/**
		 * Override the shared SignalHub instance
		 * Primarily useful for testing to inject a mock hub, or to reset
		 * the locator state. Pass null to clear the current instance.
		 * @param SignalHub|null $hub The hub instance to use, or null to clear
		 */
		public static function setInstance(?SignalHub $hub): void {
			self::$instance = $hub;
		}
	}