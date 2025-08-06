<?php
	
	namespace Quellabs\Support;
	
	/**
	 * A utility class that detects which PHP framework is currently being used
	 * in the application by checking for the presence of framework-specific classes.
	 */
	class FrameworkDetector {
		
		/**
		 * Cached framework detection result to avoid repeated detection calls
		 */
		private static ?string $cachedFramework = null;
		
		/**
		 * Detects the current framework being used, with caching for performance
		 * @return string The detected framework name
		 */
		public static function detect(): string {
			// Check if the framework has already been detected and cached
			if (self::$cachedFramework === null) {
				self::$cachedFramework = self::detectFramework();
			}
			
			// Return the cached result
			return self::$cachedFramework;
		}
		
		/**
		 * This method attempts to identify the framework by checking for the existence
		 * of key framework classes that are typically loaded when the framework is active.
		 * The detection is performed in order of preference/likelihood.
		 * @return string The detected framework name or 'unknown' if no framework is detected
		 */
		private static function detectFramework(): string {
			
			// Check for Canvas framework
			// Canvas's Kernel class is the core container and is always loaded
			if (class_exists('Quellabs\Canvas\Kernel')) {
				return 'canvas';
			}

			// Check for Laravel framework
			// Laravel's Application class is the core container and is always loaded
			if (class_exists('Illuminate\Foundation\Application')) {
				return 'laravel';
			}
			
			// Check for Symfony framework
			// The Kernel class is fundamental to Symfony's architecture and indicates Symfony usage
			if (class_exists('Symfony\Component\HttpKernel\Kernel')) {
				return 'symfony';
			}
			
			// Check for CakePHP framework
			// CakePHP's core Application class is always present in CakePHP 3.x+
			if (class_exists('Cake\Core\Application') || class_exists('App')) {
				return 'cakephp';
			}
			
			// Check for CodeIgniter framework
			// CodeIgniter 4.x uses this core class, CI 3.x uses get_instance()
			if (class_exists('CodeIgniter\CodeIgniter') || function_exists('get_instance')) {
				return 'codeigniter';
			}
			
			// Check for Zend Framework / Laminas
			// Modern Laminas or legacy Zend Framework detection
			if (class_exists('Laminas\Mvc\Application') || class_exists('Zend\Mvc\Application')) {
				return 'laminas';
			}
			
			// Check for Yii framework
			// Yii2 uses this base class, Yii1 has a different structure
			if (class_exists('yii\base\Application') || class_exists('YiiBase')) {
				return 'yii';
			}
			
			// Check for Phalcon framework
			// Phalcon's DI container is always loaded
			if (class_exists('Phalcon\Di')) {
				return 'phalcon';
			}
			
			// Check for Slim framework
			// Slim's main App class
			if (class_exists('Slim\App')) {
				return 'slim';
			}
			
			// Fallback: No recognized framework detected
			return 'unknown';
		}
	}