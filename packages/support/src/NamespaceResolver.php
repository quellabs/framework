<?php
	
	namespace Quellabs\Support;
	
	/**
	 * This class provides intelligent resolution of PHP class names by analyzing
	 * the calling context and applying namespace resolution rules similar to how
	 * PHP itself resolves class names at runtime.
	 */
	class NamespaceResolver {
		/**
		 * Cache for resolved class names to avoid repeated resolution
		 * Key format: "ClassName::ContextClass" -> "Resolved\ClassName"
		 * @var array<string, string>
		 */
		private static array $resolutionCache = [];
		
		/**
		 * Cache for enhanced imports to avoid reprocessing use statements
		 * Key: fully qualified class name -> enhanced import data structure
		 * @var array<string, array>
		 */
		private static array $enhancedImportsCache = [];
		
		/**
		 * Maximum cache size to prevent memory issues in long-running applications
		 * When exceeded, cache is trimmed to half size
		 * @var int
		 */
		private const int MAX_CACHE_SIZE = 1000;
		
		/**
		 * Resolve a class name using PHP namespace resolution rules
		 * @param string $className The class name to resolve.
		 * @param \ReflectionClass|null $reflection Optional reflection class providing context.
		 *                                          If null, will attempt to determine from call stack
		 * @return string The resolved fully qualified class name (without leading backslash)
		 * @throws \InvalidArgumentException If class name is empty string
		 */
		public static function resolveClassName(string $className, ?\ReflectionClass $reflection = null): string {
			// Validate input - PHP doesn't allow empty class names
			if ($className === '') {
				throw new \InvalidArgumentException('Class name cannot be empty');
			}
			
			// Clean up any accidental whitespace from input
			$className = trim($className);
			
			// Already fully qualified? Remove leading backslash and return
			// Example: '\App\Models\User' becomes 'App\Models\User'
			if (str_starts_with($className, '\\')) {
				return substr($className, 1);
			}
			
			// Check cache first to avoid expensive resolution for repeated requests
			$cacheKey = self::buildCacheKey($className, $reflection);

			if (isset(self::$resolutionCache[$cacheKey])) {
				return self::$resolutionCache[$cacheKey];
			}
			
			// Get or create reflection context to understand the calling environment
			$reflection = self::getReflectionContext($reflection);
			
			// If we can't determine context, return as-is and cache the result
			if ($reflection === null) {
				return self::cacheResult($cacheKey, $className);
			}
			
			// Get enhanced imports with caching to avoid reprocessing use statements
			$importsEnhanced = self::getEnhancedImports($reflection);
			
			// Apply resolution strategies in order of PHP's namespace resolution precedence
			$resolved = self::applyResolutionStrategies($className, $importsEnhanced, $reflection);
			
			// Cache and return the resolved name
			return self::cacheResult($cacheKey, $resolved);
		}
		
		/**
		 * Apply resolution strategies in order of PHP's namespace resolution precedence
		 * This follows PHP's actual resolution order to ensure consistent behavior
		 * with how PHP itself would resolve the class name at runtime.
		 * @param string $className The class name to resolve
		 * @param array $importsEnhanced Enhanced import data structure with direct mappings and segments
		 * @param \ReflectionClass $reflection Reflection of the calling class for namespace context
		 * @return string The resolved class name, or original if no resolution found
		 */
		private static function applyResolutionStrategies(
			string           $className,
			array            $importsEnhanced,
			\ReflectionClass $reflection
		): string {
			// Strategy 1: Direct import match (highest precedence)
			// Example: 'User' matches 'use App\Models\User;' → 'App\Models\User'
			$directMatch = self::resolveDirectImport($className, $importsEnhanced);
			
			if ($directMatch !== null) {
				return $directMatch;
			}
			
			// Strategy 2: Compound name resolution (namespace alias + class)
			// Example: 'Models\User' with 'use App\Models;' → 'App\Models\User'
			$compoundMatch = self::resolveCompoundName($className, $importsEnhanced);
			
			if ($compoundMatch !== null) {
				return $compoundMatch;
			}
			
			// Strategy 3: Current namespace resolution
			// Example: 'Helper' in namespace 'App\Utils' → 'App\Utils\Helper'
			$namespaceMatch = self::resolveWithCurrentNamespace($className, $reflection);
			
			if ($namespaceMatch !== null) {
				return $namespaceMatch;
			}
			
			// Strategy 4: Global namespace (lowest precedence)
			// Return as-is, assuming it's in global namespace or will be resolved later
			return $className;
		}
		
		/**
		 * Resolve direct import matches (e.g., 'User' -> 'App\Models\User')
		 * Handles simple cases where the class name exactly matches a use statement alias.
		 * This is the most common and highest precedence resolution case.
		 * @param string $className The class name to resolve (should be simple name, no backslashes)
		 * @param array $importsEnhanced Enhanced imports array with 'direct' key containing alias->FQCN mappings
		 * @return string|null The resolved FQCN if found, null if no direct match
		 */
		private static function resolveDirectImport(string $className, array $importsEnhanced): ?string {
			// Check if className has a direct mapping in the imports
			if (isset($importsEnhanced['direct'][$className])) {
				return $importsEnhanced['direct'][$className];
			}
			
			return null;
		}
		
		/**
		 * Resolve compound names (e.g., 'Utils\Helper' where 'Utils' is imported)
		 * Handles cases where the class name contains namespace separators and the first
		 * part might match an imported namespace or alias.
		 * @param string $className The compound class name to resolve (contains backslashes)
		 * @param array $importsEnhanced Enhanced imports array with direct mappings and segment data
		 * @return string|null The resolved FQCN if found and class exists, null otherwise
		 */
		private static function resolveCompoundName(string $className, array $importsEnhanced): ?string {
			// Only process if className contains namespace separator
			if (!str_contains($className, '\\')) {
				return null;
			}
			
			// Split the compound name into parts
			$parts = explode('\\', $className);
			$firstPart = $parts[0];
			$remainingParts = array_slice($parts, 1);
			
			// Check if first part matches an import alias
			if (isset($importsEnhanced['direct'][$firstPart])) {
				$baseNamespace = $importsEnhanced['direct'][$firstPart];
				$candidate = $baseNamespace . '\\' . implode('\\', $remainingParts);
				
				// Only return if the class actually exists to avoid false positives
				if (self::classExists($candidate)) {
					return $candidate;
				}
			}
			
			// Try partial namespace matches for more complex scenarios
			return self::resolvePartialNamespaceMatch($className, $importsEnhanced, $parts);
		}
		
		/**
		 * Attempts to resolve the class name by prefixing it with the current namespace
		 * of the calling class. This is PHP's default behavior when no imports match.
		 * @param string $className The class name to resolve
		 * @param \ReflectionClass $reflection Reflection of the calling class
		 * @return string|null The resolved FQCN if class exists in current namespace, null otherwise
		 */
		private static function resolveWithCurrentNamespace(string $className, \ReflectionClass $reflection): ?string {
			$currentNamespace = $reflection->getNamespaceName();
			
			// If we're already in the global namespace, can't prefix further
			if ($currentNamespace === '') {
				return null;
			}
			
			// Build candidate class name with current namespace prefix
			$candidate = $currentNamespace . '\\' . $className;
			
			// Only return if the class actually exists
			if (self::classExists($candidate)) {
				return $candidate;
			}
			
			return null;
		}
		
		/**
		 * This handles edge cases where the first part of a compound name might match
		 * a segment within an imported namespace path, enabling more flexible resolution.
		 * @param string $className The original compound class name being resolved
		 * @param array $importsEnhanced Enhanced imports containing segment mappings
		 * @param array $parts Pre-split parts of the class name
		 * @return string|null The resolved FQCN if a valid match is found, null otherwise
		 */
		private static function resolvePartialNamespaceMatch(string $className, array $importsEnhanced, array $parts): ?string {
			$firstPart = $parts[0];
			
			// Look through namespace segments for potential matches
			if (isset($importsEnhanced['segments'][$firstPart])) {
				foreach ($importsEnhanced['segments'][$firstPart] as $namespace) {
					// Build candidate by replacing first part with found namespace
					$candidate = $namespace . '\\' . implode('\\', array_slice($parts, 1));
					
					// Only return if the class actually exists
					if (self::classExists($candidate)) {
						return $candidate;
					}
				}
			}
			
			return null;
		}
		
		/**
		 * Get or create reflection context for the calling class
		 * @param \ReflectionClass|null $reflection Pre-provided reflection or null to auto-detect
		 * @return \ReflectionClass|null Reflection of the calling class, or null if cannot be determined
		 */
		private static function getReflectionContext(?\ReflectionClass $reflection): ?\ReflectionClass {
			// If reflection was provided, use it directly
			if ($reflection !== null) {
				return $reflection;
			}
			
			try {
				// Attempt to get calling context from the call stack
				$context = ContextResolver::getCallingContext();
				
				// Ensure we have valid context with a class name
				if ($context === null || !isset($context['class'])) {
					return null;
				}
				
				// Create reflection from the calling class
				return new \ReflectionClass($context['class']);
			} catch (\ReflectionException) {
				// If reflection fails, return null to fall back to basic resolution
				return null;
			}
		}
		
		/**
		 * Retrieves and processes the use statements for a given class, caching
		 * the results to avoid expensive re-parsing on repeated calls.
		 * @param \ReflectionClass $reflection The class to get imports for
		 * @return array Enhanced import data structure with direct mappings and segments
		 */
		private static function getEnhancedImports(\ReflectionClass $reflection): array {
			// Fetch class name from reflection class
			$className = $reflection->getName();
			
			// Check if we've already processed imports for this class
			if (isset(self::$enhancedImportsCache[$className])) {
				return self::$enhancedImportsCache[$className];
			}
			
			// Parse use statements from the source file
			$imports = UseStatementParser::getImportsForClass($reflection);
			
			// Enhance the raw imports for faster resolution
			$enhanced = self::enhanceImports($imports);
			
			// Manage cache size to prevent memory issues
			if (count(self::$enhancedImportsCache) >= self::MAX_CACHE_SIZE) {
				self::$enhancedImportsCache = array_slice(self::$enhancedImportsCache, -500, null, true);
			}
			
			// Return data
			return self::$enhancedImportsCache[$className] = $enhanced;
		}
		
		/**
		 * Converts raw import data from UseStatementParser into optimized data structures
		 * that enable fast lookups during resolution. Creates both direct alias mappings
		 * and segment-based mappings for compound name resolution.
		 * @param array $imports Raw imports array from UseStatementParser (alias => FQCN)
		 * @return array Enhanced structure with 'direct' and 'segments' keys.
		 */
		private static function enhanceImports(array $imports): array {
			$result = [
				'direct'   => [],     // Direct alias -> FQCN mapping for fast lookups
				'segments' => [],   // Segment -> possible namespaces mapping for compound resolution
			];
			
			foreach ($imports as $alias => $fqcn) {
				// Store direct mapping for simple alias resolution
				$result['direct'][$alias] = $fqcn;
				
				// Build segment mapping for compound name resolution
				// This enables matching partial namespace paths
				$segments = explode('\\', $fqcn);
				
				foreach ($segments as $index => $segment) {
					// Skip empty segments (shouldn't happen with valid namespaces)
					if ($segment === '') {
						continue;
					}
					
					// Build progressive namespace paths
					// e.g., for 'App\Models\User': App, App\Models, App\Models\User
					$partialNamespace = implode('\\', array_slice($segments, 0, $index + 1));
					$result['segments'][$segment][] = $partialNamespace;
				}
			}
			
			// Remove duplicates from segment mappings to save memory and improve performance
			foreach ($result['segments'] as $segment => $namespaces) {
				$result['segments'][$segment] = array_unique($namespaces);
			}
			
			return $result;
		}
		
		/**
		 * Creates a unique cache key that includes both the class name and the context
		 * to ensure resolution results are cached per calling context.
		 * @param string $className The class name being resolved
		 * @param \ReflectionClass|null $reflection The calling context or null for global context
		 * @return string Unique cache key in format "ClassName::ContextClass"
		 */
		private static function buildCacheKey(string $className, ?\ReflectionClass $reflection): string {
			$contextKey = $reflection ? $reflection->getName() : 'global';
			return $className . '::' . $contextKey;
		}
		
		/**
		 * Stores the resolution result in cache while managing memory usage by
		 * trimming the cache when it exceeds the maximum size limit.
		 * @param string $cacheKey The cache key for this resolution
		 * @param string $result The resolved class name to cache
		 * @return string The result (passed through for convenience)
		 */
		private static function cacheResult(string $cacheKey, string $result): string {
			// Manage cache size to prevent memory issues in long-running applications
			if (count(self::$resolutionCache) >= self::MAX_CACHE_SIZE) {
				// Trim cache to half size when limit exceeded (keeps most recent entries)
				self::$resolutionCache = array_slice(self::$resolutionCache, -500, null, true);
			}
			
			// Store the result and return it
			self::$resolutionCache[$cacheKey] = $result;
			
			return $result;
		}
		
		/**
		 * Check if a class/interface/trait exists (with potential for future caching)
		 * @param string $className The fully qualified class name to check
		 * @return bool True if the class, interface, or trait exists
		 */
		private static function classExists(string $className): bool {
			return class_exists($className) || interface_exists($className) || trait_exists($className);
		}
	}