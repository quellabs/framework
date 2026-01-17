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
		 * Key format: hash of (className, contextClass) -> resolved FQCN
		 * @var array<string, string>
		 */
		private static array $resolutionCache = [];
		
		/**
		 * LRU tracking for resolution cache - maps cache keys to last access timestamp
		 * @var array<string, float>
		 */
		private static array $resolutionCacheAccess = [];
		
		/**
		 * Cache for enhanced imports to avoid reprocessing use statements
		 * Key: fully qualified class name -> enhanced import data structure
		 * @var array<string, array>
		 */
		private static array $enhancedImportsCache = [];
		
		/**
		 * Maximum cache size to prevent memory issues in long-running applications
		 * When exceeded, cache is trimmed using LRU eviction
		 * @var int
		 */
		private const int MAX_CACHE_SIZE = 1000;
		
		/**
		 * Number of entries to keep when trimming cache
		 * @var int
		 */
		private const int CACHE_TRIM_SIZE = 500;
		
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
				// Update LRU tracking
				self::$resolutionCacheAccess[$cacheKey] = microtime(true);
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
		 * Clear all caches - useful for testing or after loading new classes
		 * @return void
		 */
		public static function clearCache(): void {
			self::$resolutionCache = [];
			self::$resolutionCacheAccess = [];
			self::$enhancedImportsCache = [];
		}
		
		/**
		 * Apply resolution strategies in order of PHP's namespace resolution precedence
		 * This follows PHP's actual resolution order to ensure consistent behavior
		 * with how PHP itself would resolve the class name at runtime.
		 * @param string $className The class name to resolve
		 * @param array $importsEnhanced Enhanced import data structure with direct mappings
		 * @param \ReflectionClass $reflection Reflection of the calling class for namespace context
		 * @return string The resolved class name, or original if no resolution found
		 */
		private static function applyResolutionStrategies(
			string           $className,
			array            $importsEnhanced,
			\ReflectionClass $reflection
		): string {
			$isQualified = str_contains($className, '\\');
			
			// Strategy 1: Direct import match (for unqualified names only)
			// Example: 'User' matches 'use App\Models\User;' → 'App\Models\User'
			if (!$isQualified) {
				$directMatch = self::resolveDirectImport($className, $importsEnhanced);
				if ($directMatch !== null) {
					return $directMatch;
				}
			}
			
			// Strategy 2: Qualified name resolution (namespace prefix or alias)
			// Example: 'Models\User' with 'use App\Models;' → 'App\Models\User'
			if ($isQualified) {
				$qualifiedMatch = self::resolveQualifiedName($className, $importsEnhanced);
				if ($qualifiedMatch !== null) {
					return $qualifiedMatch;
				}
			}
			
			// Strategy 3: Current namespace resolution (for unqualified names only)
			// Example: 'Helper' in namespace 'App\Utils' → 'App\Utils\Helper'
			if (!$isQualified) {
				$namespaceMatch = self::resolveWithCurrentNamespace($className, $reflection);

				if ($namespaceMatch !== null) {
					return $namespaceMatch;
				}
			}
			
			// Strategy 4: Global namespace fallback
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
			return $importsEnhanced['direct'][$className] ?? null;
		}
		
		/**
		 * Resolve qualified names (e.g., 'Models\User' where 'Models' is imported as namespace)
		 * This handles cases where the first segment matches either:
		 * 1. A namespace import: use App\Models; → Models\User becomes App\Models\User
		 * 2. A class alias used as namespace prefix (technically invalid PHP, but we check anyway)
		 * @param string $className The qualified class name to resolve (contains backslashes)
		 * @param array $importsEnhanced Enhanced imports array with direct and namespace mappings
		 * @return string|null The resolved FQCN if found, null otherwise
		 */
		private static function resolveQualifiedName(string $className, array $importsEnhanced): ?string {
			// Split only once to get first segment and remainder
			$separatorPos = strpos($className, '\\');
			if ($separatorPos === false) {
				return null; // Should never happen due to caller check, but be defensive
			}
			
			$firstSegment = substr($className, 0, $separatorPos);
			$remainder = substr($className, $separatorPos + 1);
			
			// Strategy 1: Check if first segment matches a namespace import
			if (isset($importsEnhanced['namespaces'][$firstSegment])) {
				$baseNamespace = $importsEnhanced['namespaces'][$firstSegment];
				return $baseNamespace . '\\' . $remainder;
			}
			
			// Strategy 2: Parent namespace matching
			// Example: 'Orm\Table' with 'use Quellabs\ObjectQuel\Annotations\Orm\Table;'
			// This handles annotation syntax like @Orm\Table where full path is imported
			foreach ($importsEnhanced['direct'] as $alias => $fqcn) {
				// Check if the FQCN ends with our qualified class name
				if (str_ends_with($fqcn, '\\' . $className)) {
					return $fqcn;
				}
			}
			
			// Strategy 3: Check if first segment matches a class alias (edge case)
			// This handles: use App\Models\User as Models; Models\Helper would try App\Models\User\Helper
			// This is technically invalid PHP usage, but we check for consistency
			if (isset($importsEnhanced['direct'][$firstSegment])) {
				$baseClass = $importsEnhanced['direct'][$firstSegment];
				$candidate = $baseClass . '\\' . $remainder;
				
				// Only return if the class actually exists to avoid false positives
				if (self::classExistsCached($candidate)) {
					return $candidate;
				}
			}
			
			return null;
		}
		
		/**
		 * Attempts to resolve the class name by prefixing it with the current namespace
		 * of the calling class. This is PHP's default behavior when no imports match.
		 * @param string $className The class name to resolve (must be unqualified)
		 * @param \ReflectionClass $reflection Reflection of the calling class
		 * @return string|null The resolved FQCN if class exists in current namespace, null otherwise
		 */
		private static function resolveWithCurrentNamespace(string $className, \ReflectionClass $reflection): ?string {
			// Fetch namespace
			$currentNamespace = $reflection->getNamespaceName();
			
			// If we're already in the global namespace, can't prefix further
			if ($currentNamespace === '') {
				return null;
			}
			
			// Build candidate class name with current namespace prefix
			$candidate = $currentNamespace . '\\' . $className;
			
			// Only return if the class actually exists
			if (self::classExistsCached($candidate)) {
				return $candidate;
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
			} catch (\ReflectionException $e) {
				// Log the error for debugging but don't expose it to caller
				// In production, you might want to log this to your error handler
				// error_log('NamespaceResolver: Failed to create reflection - ' . $e->getMessage());
				return null;
			}
		}
		
		/**
		 * Retrieves and processes the use statements for a given class, caching
		 * the results to avoid expensive re-parsing on repeated calls.
		 * @param \ReflectionClass $reflection The class to get imports for
		 * @return array Enhanced import data structure with direct and namespace mappings
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
			
			// Validate imports structure
			if (!is_array($imports)) {
				$imports = [];
			}
			
			// Enhance the raw imports for faster resolution
			$enhanced = self::enhanceImports($imports);
			
			// Manage cache size to prevent memory issues
			if (count(self::$enhancedImportsCache) >= self::MAX_CACHE_SIZE) {
				self::$enhancedImportsCache = array_slice(
					self::$enhancedImportsCache,
					-self::CACHE_TRIM_SIZE,
					null,
					true
				);
			}
			
			// Cache and return
			self::$enhancedImportsCache[$className] = $enhanced;
			return $enhanced;
		}
		
		/**
		 * Converts raw import data from UseStatementParser into optimized data structures
		 * that enable fast lookups during resolution. Separates class imports from namespace imports.
		 * @param array $imports Raw imports array from UseStatementParser (alias => FQCN)
		 * @return array Enhanced structure with 'direct' and 'namespaces' keys.
		 */
		private static function enhanceImports(array $imports): array {
			$result = [
				'direct'     => [],  // Direct class alias -> FQCN mapping
				'namespaces' => [],  // Namespace alias -> namespace path mapping
			];
			
			foreach ($imports as $alias => $fqcn) {
				// Validate structure - skip malformed entries
				if (!is_string($alias) || !is_string($fqcn) || $alias === '' || $fqcn === '') {
					continue;
				}
				
				// Store direct mapping for alias resolution
				$result['direct'][$alias] = $fqcn;
				
				// Detect namespace imports by checking if the alias matches the last segment
				// Example: 'use App\Models;' creates alias 'Models' -> 'App\Models'
				// Example: 'use App\Models as M;' creates alias 'M' -> 'App\Models'
				$lastSegment = self::getLastSegment($fqcn);
				
				// If FQCN ends with backslash or alias matches last segment without a class name,
				// this is likely a namespace import
				if ($lastSegment === $alias || str_ends_with($fqcn, '\\')) {
					$result['namespaces'][$alias] = rtrim($fqcn, '\\');
				}
			}
			
			return $result;
		}
		
		/**
		 * Extract the last segment from a fully qualified name
		 * @param string $fqcn Fully qualified class or namespace name
		 * @return string The last segment after the final backslash, or the whole string if no backslash
		 */
		private static function getLastSegment(string $fqcn): string {
			$pos = strrpos($fqcn, '\\');
			return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
		}
		
		/**
		 * Creates a unique cache key that includes both the class name and the context
		 * to ensure resolution results are cached per calling context.
		 * Uses hash to prevent collision issues with concatenation.
		 * @param string $className The class name being resolved
		 * @param \ReflectionClass|null $reflection The calling context or null for global context
		 * @return string Unique cache key as hash
		 */
		private static function buildCacheKey(string $className, ?\ReflectionClass $reflection): string {
			$contextKey = $reflection ? $reflection->getName() : '';
			// Use hash to prevent collision and save memory for long class names
			return hash('xxh3', $className . "\0" . $contextKey);
		}
		
		/**
		 * Stores the resolution result in cache while managing memory usage using LRU eviction
		 * when the cache exceeds the maximum size limit.
		 * @param string $cacheKey The cache key for this resolution
		 * @param string $result The resolved class name to cache
		 * @return string The result (passed through for convenience)
		 */
		private static function cacheResult(string $cacheKey, string $result): string {
			// Manage cache size using LRU eviction
			if (count(self::$resolutionCache) >= self::MAX_CACHE_SIZE) {
				// Sort by access time and keep most recently used entries
				asort(self::$resolutionCacheAccess);
				$toRemove = array_slice(
					array_keys(self::$resolutionCacheAccess),
					0,
					self::MAX_CACHE_SIZE - self::CACHE_TRIM_SIZE,
					true
				);
				
				foreach ($toRemove as $key) {
					unset(self::$resolutionCache[$key], self::$resolutionCacheAccess[$key]);
				}
			}
			
			// Store the result with access tracking
			self::$resolutionCache[$cacheKey] = $result;
			self::$resolutionCacheAccess[$cacheKey] = microtime(true);
			
			return $result;
		}
		
		/**
		 * Check if a class/interface/trait exists with caching to avoid repeated autoloader calls
		 * Only checks existence without triggering autoloader for performance
		 * @param string $className The fully qualified class name to check
		 * @return bool True if the class, interface, or trait exists
		 */
		private static function classExistsCached(string $className): bool {
			// Check if already loaded (fast path - no autoloader invocation)
			if (class_exists($className, false) ||
				interface_exists($className, false) ||
				trait_exists($className, false)) {
				return true;
			}
			
			// Try loading with autoloader (slower path)
			// Note: This may still be expensive if the class doesn't exist
			// In high-performance scenarios, you might want to add a negative cache here
			return class_exists($className) ||
				interface_exists($className) ||
				trait_exists($className);
		}
	}