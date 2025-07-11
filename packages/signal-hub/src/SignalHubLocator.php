<?php
	
	namespace Quellabs\SignalHub;
	
	/**
	 * Locator for accessing a shared SignalHub instance
	 */
	class SignalHubLocator {
		private static ?SignalHub $instance = null;
		
		public static function getInstance(): SignalHub {
			if (self::$instance === null) {
				self::$instance = new SignalHub();
			}
			
			return self::$instance;
		}
		
		public static function setInstance(?SignalHub $hub): void {
			self::$instance = $hub;
		}
	}