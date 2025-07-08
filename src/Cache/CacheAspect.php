<?php
	
	namespace Quellabs\Canvas\Cache;
	
	use Quellabs\Canvas\Cache\Foundation\FileCache;
	use Quellabs\Contracts\AOP\AroundAspect;
	use Quellabs\Contracts\AOP\MethodContext;
	use Psr\Log\LoggerInterface;
	use Psr\Log\NullLogger;
	
	/**
	 * Caches the return value of controller methods using configurable cache keys and TTL.
	 *
	 * This aspect provides method-level caching with the following features:
	 * - Cache keys based on request URI
	 * - Configurable TTL and cache contexts
	 * - Graceful fallback when caching fails
	 * - Thread-safe caching using FileCache with concurrency protection
	 *
	 * The cache key is constructed by combining the configured key template
	 * with the complete request URI (path + query string).
	 *
	 * Example:
	 * - Key template: "products"
	 * - Request URI: "/products/123?page=2&sort=name"
	 * - Final cache key: "products./products/123?page=2&sort=name"
	 */
	class CacheAspect implements AroundAspect {
		
		/** @var string Cache key template */
		private string $key;
		
		/** @var int Time to live in seconds */
		private int $ttl;
		
		/** @var string Cache context/namespace */
		private string $context;
		
		/** @var string Cache storage path */
		private string $cachePath;
		
		/** @var LoggerInterface Logger for cache operations */
		private LoggerInterface $logger;
		
		/** @var int Lock timeout for cache operations */
		private int $lockTimeout;
		
		/** @var bool Whether to gracefully handle cache failures */
		private bool $gracefulFallback;
		
		/**
		 * Constructor
		 *
		 * @param string $key Cache key template (supports request-based substitution)
		 * @param int $ttl Time to live in seconds (0 = never expires)
		 * @param string $context Cache context for namespacing
		 * @param string $cachePath Base cache directory path
		 * @param LoggerInterface|null $logger Logger for cache operations
		 * @param int $lockTimeout Lock timeout in seconds for cache operations
		 * @param bool $gracefulFallback Whether to execute method if caching fails
		 */
		public function __construct(
			string           $key,
			int              $ttl = 3600,
			string           $context = 'default',
			string           $cachePath = '/storage/cache',
			?LoggerInterface $logger = null,
			int              $lockTimeout = 5,
			bool             $gracefulFallback = true
		) {
			$this->key = $key;
			$this->ttl = max(0, $ttl); // Ensure non-negative TTL
			$this->context = $context;
			$this->cachePath = $cachePath;
			$this->logger = $logger ?? new NullLogger();
			$this->lockTimeout = max(1, $lockTimeout); // Ensure positive timeout
			$this->gracefulFallback = $gracefulFallback;
		}
		
		/**
		 * Cache the method execution result
		 *
		 * This method implements the around advice pattern:
		 * 1. Resolve the cache key from the template
		 * 2. Check cache for existing result
		 * 3. Execute original method if cache miss
		 * 4. Store result in cache for future requests
		 * 5. Return cached or computed result
		 *
		 * If caching operations fail and gracefulFallback is enabled,
		 * the original method will still be executed to ensure functionality.
		 *
		 * @param MethodContext $context Method execution context
		 * @param callable $proceed Callback to execute the original method
		 * @return mixed Cached or computed result
		 * @throws \RuntimeException If caching fails and gracefulFallback is disabled
		 */
		public function around(MethodContext $context, callable $proceed): mixed {
			$cacheKey = null;
			
			try {
				// Initialize cache with concurrency protection
				$cache = new FileCache($this->cachePath, $this->context, $this->lockTimeout);
				
				// Resolve a dynamic cache key
				$cacheKey = $this->resolveCacheKey($context);
				
				// Use cache remember pattern for atomic cache-aside operations
				return $cache->remember($cacheKey, $this->ttl, function () use ($proceed, $cacheKey) {
					$this->logger->debug('Cache miss, executing original method', ['key' => $cacheKey]);
					return $proceed();
				});
				
			} catch (\Exception $e) {
				// Log cache failure
				$this->logger->error('Cache aspect failed', [
					'error'      => $e->getMessage(),
					'key'        => $cacheKey ?? 'unknown',
					'controller' => get_class($context->getTarget()),
					'method'     => $context->getMethodName()
				]);
				
				// Handle cache failure based on configuration
				if ($this->gracefulFallback) {
					// Execute original method without caching
					$this->logger->info('Cache aspect failed. Falling back to uncached execution');
					return $proceed();
				}
				
				// Re-throw exception if graceful fallback is disabled
				throw new \RuntimeException('Cache aspect failed: ' . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Resolve the cache key from request URI
		 * @param MethodContext $context Method execution context
		 * @return string Cache key based on request URI
		 */
		private function resolveCacheKey(MethodContext $context): string {
			// Fetch the request from the context
			$request = $context->getRequest();
			
			// Combine the key template with the URI
			$cacheKey = $this->key . '.' . $this->sanitizeValue($request->getRequestUri());
			
			// Validate and normalize the final cache key
			return $this->normalizeCacheKey($cacheKey);
		}
		
		/**
		 * Sanitize a value for use in cache keys
		 * @param mixed $value Value to sanitize
		 * @return string Sanitized value
		 */
		private function sanitizeValue(mixed $value): string {
			// Convert to string and limit length
			$stringValue = substr((string)$value, 0, 100);
			
			// Replace problematic characters with safe alternatives
			$sanitized = preg_replace('/[^a-zA-Z0-9\-_.\\/]/', '-', $stringValue);
			
			// Remove consecutive dashes and trim
			$sanitized = preg_replace('/-+/', '-', $sanitized);
			$sanitized = trim($sanitized, '-');
			
			// Ensure we have a non-empty result
			return $sanitized ?: 'empty';
		}
		
		/**
		 * Normalize the cache key
		 *
		 * This method ensures the cache key meets requirements:
		 * - Not too long (cache systems have key length limits)
		 * - Contains only safe characters
		 * - Has a reasonable structure
		 *
		 * @param string $key Cache key to validate
		 * @return string Validated cache key
		 */
		private function normalizeCacheKey(string $key): string {
			// Remove any remaining unreplaced placeholders
			$key = preg_replace('/\{[^}]+\}/', 'missing', $key);
			
			// Ensure key isn't too long (many cache systems have 250 char limits)
			if (strlen($key) > 200) {
				// Hash the key if it's too long, but preserve some readable portion
				$prefix = substr($key, 0, 100);
				$hash = hash('sha256', $key);
				$key = $prefix . '.' . substr($hash, 0, 16);
			}
			
			// Final cleanup
			$key = trim($key, '.-');
			
			// Ensure we have a valid key
			return $key ?: 'default.cache.key';
		}
		
		/**
		 * Get cache statistics for monitoring
		 * @return array Cache configuration and statistics
		 */
		public function getCacheInfo(): array {
			return [
				'key_template'      => $this->key,
				'ttl'               => $this->ttl,
				'context'           => $this->context,
				'cache_path'        => $this->cachePath,
				'lock_timeout'      => $this->lockTimeout,
				'graceful_fallback' => $this->gracefulFallback
			];
		}
	}