<?php
	
	namespace Quellabs\Support;
	
	class ContextResolver {
		
		/** @var array Cache for calling context information to improve performance */
		private static array $contextCache = [];
		
		/** @var array Framework namespaces to exclude when finding calling context */
		private static array $excludedNamespaces = [
			// Canvas ecosystem
			'Quellabs\\',
			
			// Laravel
			'Illuminate\\',
			'Laravel\\',
			
			// Symfony
			'Symfony\\',
			
			// Zend/Laminas Framework
			'Zend\\',
			'Laminas\\',
			
			// CodeIgniter 4
			'CodeIgniter\\',
			
			// CakePHP
			'Cake\\',
			
			// Yii Framework
			'yii\\',
			'Yii\\',
			
			// Phalcon Framework
			'Phalcon\\',
			
			// Slim Framework
			'Slim\\',
			
			// PHPUnit Testing Framework
			'PHPUnit\\',
			
			// Composer
			'Composer\\',
			
			// Monolog Logger
			'Monolog\\',
			
			// Guzzle HTTP Client
			'GuzzleHttp\\',
			
			// Carbon Date Library
			'Carbon\\',
			
			// Twig Template Engine
			'Twig\\',
			
			// Swift Mailer / Symfony Mailer
			'Swift_',
			'Symfony\\Component\\Mailer\\',
			
			// PHPStan
			'PHPStan\\',
			
			// Psalm
			'Psalm\\',
			
			// ReactPHP
			'React\\',
			
			// Ratchet WebSocket
			'Ratchet\\',
			
			// League packages (common prefix)
			'League\\',
		];
		
		/**
		 * Analyzes the call stack to find the first frame that's not part of any
		 * excluded framework namespace, which represents the actual application code
		 * that initiated the class name resolution.
		 * @return array|null Array containing file, class, function, and line information, or null if not found
		 */
		public static function getCallingContext(): ?array {
			// Get call stack trace (limit to 50 frames for performance, ignore function arguments)
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			
			// Walk through the call stack to find non-framework code
			foreach ($trace as $frame) {
				// Skip frames without class info or frames from excluded namespaces
				// This ensures we find the actual application code, not internal framework calls
				if (!isset($frame['class']) || self::isExcludedClass($frame['class'])) {
					continue;
				}
				
				// Use class name as cache key for performance
				$cacheKey = $frame['class'];
				
				// Return cached context if available
				if (isset(self::$contextCache[$cacheKey])) {
					return self::$contextCache[$cacheKey];
				}
				
				// Build context information using reflection
				try {
					$reflection = new \ReflectionClass($frame['class']);
					
					return self::$contextCache[$cacheKey] = [
						'file'     => $reflection->getFileName() ?: null, // Source file path
						'class'    => $frame['class'],                   // Fully qualified class name
						'function' => $frame['function'] ?? null,        // Method name that made the call
						'line'     => $frame['line'] ?? null             // Line number of the call
					];
				} catch (\ReflectionException $e) {
					// If reflection fails for this class, try the next frame
					continue;
				}
			}
			
			// No suitable calling context found
			return null;
		}
		
		/**
		 * Check if a class name should be excluded based on namespace
		 * @param string $className Fully qualified class name
		 * @return bool True if class should be excluded, false otherwise
		 */
		private static function isExcludedClass(string $className): bool {
			foreach (self::$excludedNamespaces as $namespace) {
				if (str_starts_with($className, $namespace)) {
					return true;
				}
			}
			
			return false;
		}
	}