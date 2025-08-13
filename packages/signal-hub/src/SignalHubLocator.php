<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║  ███████╗██╗ ██████╗ ███╗   ██╗ █████╗ ██╗     ██╗  ██╗██╗   ██╗██████╗              ║
	 * ║  ██╔════╝██║██╔════╝ ████╗  ██║██╔══██╗██║     ██║  ██║██║   ██║██╔══██╗             ║
	 * ║  ███████╗██║██║  ███╗██╔██╗ ██║███████║██║     ███████║██║   ██║██████╔╝             ║
	 * ║  ╚════██║██║██║   ██║██║╚██╗██║██╔══██║██║     ██╔══██║██║   ██║██╔══██╗             ║
	 * ║  ███████║██║╚██████╔╝██║ ╚████║██║  ██║███████╗██║  ██║╚██████╔╝██████╔╝             ║
	 * ║  ╚══════╝╚═╝ ╚═════╝ ╚═╝  ╚═══╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝ ╚═════╝ ╚═════╝              ║
	 * ║                                                                                       ║
	 * ║  SignalHub - Type-Safe Signal-Slot System for PHP                                    ║
	 * ║                                                                                       ║
	 * ║  Qt-inspired signal-slot implementation with strong type checking and flexible       ║
	 * ║  connection options for loose coupling between components while maintaining safety.   ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
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