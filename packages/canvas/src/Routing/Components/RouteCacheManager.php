<?php
	
	namespace Quellabs\Canvas\Routing\Components;
	
	use FilesystemIterator;
	use RecursiveDirectoryIterator;
	use RecursiveIteratorIterator;
	use Quellabs\Canvas\Cache\Foundation\FileCache;
	
	/**
	 * RouteCacheManager
	 *
	 * Manages route caching and optimization using the FileCache system to provide
	 * persistent storage of compiled routes with intelligent cache invalidation based
	 * on controller file modifications.
	 *
	 * Core responsibilities:
	 * - Route caching: Stores compiled routes and indexes to avoid expensive rebuilds
	 * - Cache invalidation: Automatically detects controller file changes
	 * - File modification tracking: Monitors controller directory for updates
	 * - Debug mode handling: Bypasses caching during development
	 * - Concurrency protection: Uses FileCache atomic operations for thread safety
	 * - Cache statistics: Provides metrics on cache performance and status
	 *
	 * Caching strategy:
	 * - Production mode: Aggressive caching with file modification detection
	 * - Debug mode: Cache bypass for immediate reflection of code changes
	 * - Intelligent invalidation: Compares controller modification times
	 * - Atomic operations: Thread-safe cache updates using FileCache
	 * - Configurable TTL: Default 24-hour cache lifetime with override support
	 *
	 * Cache validation process:
	 * 1. Checks if cached routes exist
	 * 2. Compares current controller modification times with cached times
	 * 3. Invalidates cache if any controller files changed
	 * 4. Falls back to route rebuilding if cache is stale or missing
	 * 5. Stores new controller modification times alongside routes
	 *
	 * File monitoring:
	 * - Recursive directory scanning for PHP files
	 * - Modification time tracking for cache invalidation
	 * - Error handling for filesystem access issues
	 * - Graceful degradation when file scanning fails
	 *
	 * Performance impact:
	 * - Cache hits: Near-instant route loading (microseconds)
	 * - Cache misses: Full route discovery and compilation (milliseconds)
	 * - File monitoring: Lightweight timestamp comparisons
	 * - Memory efficiency: Routes loaded once per application lifecycle
	 *
	 * The cache manager ensures optimal performance in production while maintaining
	 * development-friendly behavior with automatic cache invalidation when controllers
	 * are modified.
	 */
	class RouteCacheManager {
		
		private FileCache $cache;
		private bool $debugMode;
		private string $controllerDirectory;
		private ?int $lastControllerModification = null;
		private int $defaultTtl;
		
		/**
		 * Cache key for storing compiled routes
		 */
		private const string ROUTES_CACHE_KEY = 'compiled_routes';
		
		/**
		 * Cache key for storing controller modification times
		 */
		private const string CONTROLLER_MTIME_KEY = 'controller_modification_time';
		
		/**
		 * RouteCacheManager constructor
		 * @param FileCache $cache
		 * @param bool $debugMode
		 * @param string $controllerDirectory
		 * @param int $defaultTtl
		 */
		public function __construct(
			FileCache $cache,
			bool      $debugMode,
			string    $controllerDirectory,
			int       $defaultTtl = 86400 // 24 hours default
		) {
			$this->cache = $cache;
			$this->debugMode = $debugMode;
			$this->controllerDirectory = $controllerDirectory;
			$this->defaultTtl = $defaultTtl;
		}
		
		/**
		 * Get cached routes if available and valid
		 * @param callable $routeBuilder Callback to build routes if cache miss
		 * @return array Cached or freshly built routes array
		 */
		public function getCachedRoutes(callable $routeBuilder): array {
			// Skip caching entirely in debug mode for development flexibility
			if ($this->debugMode) {
				return $routeBuilder();
			}
			
			// Check if controller files have been modified since last cache
			// This must happen BEFORE trying to retrieve from cache
			if ($this->haveControllersChanged()) {
				// Controllers changed, clear related cache and rebuild
				$this->clearCache();
			}
			
			// Use FileCache remember() for intelligent caching with concurrency protection
			return $this->cache->remember(
				self::ROUTES_CACHE_KEY,
				$this->defaultTtl,
				function () use ($routeBuilder) {
					// Build fresh routes
					$routes = $routeBuilder();
					
					// Cache the current controller modification time
					$this->cacheControllerModificationTime();
					
					// Return the routes
					return $routes;
				}
			);
		}
		
		/**
		 * Check if cache is valid by comparing modification times
		 * @return bool True if cache is still valid, false if stale
		 */
		public function isCacheValid(): bool {
			if ($this->debugMode) {
				return false;
			}
			
			// Check if routes exist in the cache
			if (!$this->cache->has(self::ROUTES_CACHE_KEY)) {
				return false;
			}
			
			// Check if controller modification time has changed
			return !$this->haveControllersChanged();
		}
		
		/**
		 * Clear all cached routes and related data
		 * @return bool True if cache was cleared successfully
		 */
		public function clearCache(): bool {
			$routesCleared = $this->cache->forget(self::ROUTES_CACHE_KEY);
			$mtimeCleared = $this->cache->forget(self::CONTROLLER_MTIME_KEY);
			
			// Reset in-memory cache
			$this->lastControllerModification = null;
			
			return $routesCleared && $mtimeCleared;
		}
		
		/**
		 * Get cache statistics and information
		 * @return array Associative array with cache statistics
		 */
		public function getCacheInfo(): array {
			return [
				'debug_mode'                     => $this->debugMode,
				'controller_directory'           => $this->controllerDirectory,
				'cache_context'                  => 'routes', // Assuming routes context
				'routes_cached'                  => $this->cache->has(self::ROUTES_CACHE_KEY),
				'cache_valid'                    => $this->isCacheValid(),
				'last_controller_modification'   => $this->getLastControllerModification(),
				'cached_controller_modification' => $this->cache->get(self::CONTROLLER_MTIME_KEY, 0),
				'controllers_changed'            => $this->haveControllersChanged()
			];
		}

		/**
		 * Get last modification time of controller files
		 * @return int Unix timestamp of the most recently modified controller file
		 */
		public function getLastControllerModification(): int {
			// Use cached result if available to avoid expensive filesystem operations
			// on subsequent calls within the same request
			if ($this->lastControllerModification !== null) {
				return $this->lastControllerModification;
			}
			
			// Return 0 if controller directory is not set or doesn't exist
			// This indicates no controllers are available for modification checking
			if (!$this->controllerDirectory || !is_dir($this->controllerDirectory)) {
				return $this->lastControllerModification = 0;
			}
			
			// Initialize with 0 to track the maximum modification time found
			$maxTime = 0;
			
			try {
				// Create recursive iterator to traverse all subdirectories
				// RecursiveDirectoryIterator explores directory structure recursively
				// FilesystemIterator::SKIP_DOTS excludes "." and ".." entries
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($this->controllerDirectory, FilesystemIterator::SKIP_DOTS)
				);
				
				// Iterate through each file in the directory tree
				foreach ($iterator as $file) {
					// Only process PHP files since controllers are typically PHP files
					if ($file->getExtension() === 'php') {
						// Update maxTime with the latest modification time found
						// getMTime() returns the Unix timestamp of last modification
						$maxTime = max($maxTime, $file->getMTime());
					}
				}
			} catch (\Exception $e) {
				// Log any filesystem errors (permissions, missing files, etc.)
				// This prevents the application from crashing due to filesystem issues
				error_log("RouteCacheManager: Error scanning controller directory: " . $e->getMessage());
				
				// Return current timestamp as fallback to force cache invalidation
				// This ensures the system remains functional even when file scanning fails
				return $this->lastControllerModification = time();
			}
			
			// Cache and return the maximum modification time found
			// If no PHP files were found, maxTime remains 0
			return $this->lastControllerModification = $maxTime;
		}
		
		/**
		 * Check if controller files have been modified since last cache
		 * @return bool True if controllers have changed since last cache
		 */
		private function haveControllersChanged(): bool {
			$currentModificationTime = $this->getLastControllerModification();
			$cachedModificationTime = $this->cache->get(self::CONTROLLER_MTIME_KEY, 0);
			
			return $currentModificationTime > $cachedModificationTime;
		}
		
		/**
		 * Cache the current controller modification time
		 * @return void True if successfully cached
		 */
		private function cacheControllerModificationTime(): void {
			$currentTime = $this->getLastControllerModification();
			$this->cache->set(self::CONTROLLER_MTIME_KEY, $currentTime, $this->defaultTtl);
		}
	}