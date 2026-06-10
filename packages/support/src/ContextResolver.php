<?php
	
	namespace Quellabs\Support;
	
	/**
	 * @phpstan-type CallingContext array{
	 *     file: string,
	 *     class: string|null,
	 *     function: string,
	 *     line: int|null
	 * }
	 */
	class ContextResolver {
		
		/**
		 * Cache mapping fully qualified class names to their definition file paths.
		 * This is safe to cache because a class definition file never changes within
		 * a process lifetime. Only the file is cached — not function or line number,
		 * which vary per call site.
		 *
		 * Internal PHP classes (DateTime, PDO, Exception, etc.) return false from
		 * getFileName() and are intentionally not cached, because their fallback
		 * value is the call-site-specific $default which differs per invocation.
		 *
		 * @var array<string, string>
		 */
		private static array $fileCache = [];
		
		/**
		 * Framework namespaces to exclude when finding calling context.
		 * Extend this list via addExcludedNamespace() for project-specific exclusions.
		 *
		 * @var array<int, string>
		 */
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
		 * Register an additional namespace prefix to exclude from calling context
		 * resolution. Useful for application-specific infrastructure namespaces
		 * that should not appear as the originating caller.
		 *
		 * Duplicate registrations are silently ignored.
		 * @param string $namespace Fully qualified namespace prefix, e.g. 'MyApp\Infrastructure\\'
		 * @return void
		 */
		public static function addExcludedNamespace(string $namespace): void {
			if (!in_array($namespace, self::$excludedNamespaces, true)) {
				self::$excludedNamespaces[] = $namespace;
			}
		}
		
		/**
		 * Analyzes the call stack to find the first frame that is not part of any
		 * excluded framework namespace, which represents the actual application code
		 * that initiated the resolution.
		 *
		 * No context caching is performed here: function name and line number are
		 * call-site-specific and differ across invocations from the same class.
		 * Only the class-to-file mapping is cached separately via $fileCache.
		 *
		 * The frame limit of 50 is intentional. It is generous enough to cover deep
		 * middleware stacks while bounding worst-case overhead. Adjust only if a
		 * specific integration is observed to exceed this depth.
		 * @return CallingContext|null Array containing file, class, function, and line information, or null if not found
		 */
		public static function getCallingContext(): ?array {
			// Get call stack trace (limit to 50 frames for performance, ignore function arguments)
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
			
			// Walk through the call stack to find non-framework code
			foreach ($trace as $frame) {
				// Skip excluded namespaces
				// This ensures we find actual application code, not internal framework calls
				if (isset($frame['class']) && self::isExcludedClass($frame['class'])) {
					continue;
				}
				
				$className = $frame['class'] ?? null;
				$fileName = self::getFileName($className, $frame['file'] ?? 'unknown');
				
				return [
					'file'     => $fileName,                   // Source file path
					'class'    => $className,                  // Fully qualified class name (can be null)
					'function' => $frame['function'],          // Method name that made the call
					'line'     => $frame['line'] ?? null       // Line number of the call
				];
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
		
		/**
		 * Returns the file in which $className is defined. The class definition file
		 * is preferred over the raw $frame['file'] from debug_backtrace() because
		 * backtrace file/line reflects the call site, which may be a trait use,
		 * inherited method, or generated proxy — not the authoritative source file.
		 * ReflectionClass resolves this unambiguously.
		 *
		 * The result is cached by class name only when reflection returns a real path.
		 * Internal PHP classes (DateTime, PDO, etc.) return false from getFileName()
		 * and are not cached, because their $default is call-site-specific and would
		 * produce incorrect results for subsequent calls from different files.
		 *
		 * If $className is null or reflection fails (class not loadable), $default is returned.
		 * @param string|null $className
		 * @param string $default
		 * @return string
		 */
		private static function getFileName(?string $className, string $default): string {
			if ($className === null) {
				return $default;
			}
			
			// Return cached file path if already resolved for this class
			if (isset(self::$fileCache[$className])) {
				return self::$fileCache[$className];
			}
			
			try {
				/** @var class-string $className */
				$reflection = new \ReflectionClass($className);
				$file = $reflection->getFileName();
				
				// Internal PHP classes (DateTime, PDO, Exception, etc.) return false here.
				// Do not cache the fallback: $default is call-site-specific and would
				// produce incorrect results if stored and returned for a later call.
				if ($file === false) {
					return $default;
				}
				
				return self::$fileCache[$className] = $file;
			} catch (\ReflectionException) {
				// Class is not loadable (e.g. dynamically generated or already unloaded).
				// Fall back to the raw frame file without caching, since the class
				// may become available in a later call.
				return $default;
			}
		}
	}