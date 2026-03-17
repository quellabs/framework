<?php
	
	namespace Quellabs\DependencyInjection;
	
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	
	/**
	 * Locator for accessing a shared Container instance
	 * This class implements the Service Locator pattern to provide
	 * global access to a single Container instance
	 */
	class ContainerLocator {
		
		/**
		 * Static property to hold the singleton container instance
		 * Using nullable type (?) to indicate it can be null before initialization
		 * @var ContainerInterface|null
		 */
		private static ?ContainerInterface $instance = null;
		
		/**
		 * Gets the singleton container instance
		 * If no instance exists yet, initializes a new Container
		 * @return ContainerInterface The shared container instance
		 */
		public static function getInstance(): ContainerInterface {
			// Lazy initialization - only create container when first needed
			if (self::$instance === null) {
				self::$instance = new \Quellabs\DependencyInjection\Container();
			}
			
			return self::$instance;
		}
		
		/**
		 * Sets the container instance
		 * Allows for container replacement, typically for testing purposes
		 * or advanced configuration scenarios
		 * @param ContainerInterface|null $hub The container instance to use, or null to clear
		 * @return void
		 */
		public static function setInstance(?ContainerInterface $hub): void {
			self::$instance = $hub;
		}
	}