# Cache Components

A robust, production-ready caching system for PHP applications with multiple storage backends and comprehensive concurrency protection.

## Features

- **Multiple Cache Drivers**: File-based, Redis, and Memcached implementations
- **Thread-Safe Operations**: Comprehensive concurrency protection across all drivers
- **Dependency Injection**: Automatic cache provider resolution with annotation support
- **Namespace Support**: Context-aware caching with namespace isolation
- **Retry Logic**: Built-in resilience against transient failures
- **Statistics & Monitoring**: Performance metrics and cache hit ratio tracking
- **AOP Integration**: Seamless aspect-oriented programming support

## Installation

Ensure required PHP extensions are installed based on your chosen cache driver:

```bash
# For Redis support
sudo apt-get install php-redis

# For Memcached support  
sudo apt-get install php-memcached

# File cache requires no additional extensions
```

## Configuration

### Dependency Injection Configuration

When using the cache through dependency injection, create a `config/cache.php` file in your project root.
The injector will automatically load and process the correct options.

```php
<?php
return [
    'default' => 'file',
    
    'drivers' => [
        'file' => [
            'class' => \Quellabs\Cache\FileCache::class,
        ],
        
        'redis' => [
            'class' => \Quellabs\Cache\RedisCache::class,
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 2.5,
            'database' => 0,
            'password' => null,
        ],
        
        'memcached' => [
            'class' => \Quellabs\Cache\MemcachedCache::class,
            'servers' => [
                ['127.0.0.1', 11211, 100]
            ],
            'persistent_id' => 'my_app',
            'binary_protocol' => true,
            'compression' => true,
        ],
    ],
];
```

### Direct Instantiation Configuration

When creating cache instances directly, pass configuration options to the constructor:

```php
// File cache - no additional config needed
$fileCache = new FileCache('namespace', 5); // namespace, lock timeout

// Redis cache with custom config
$redisConfig = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'timeout' => 2.5,
    'database' => 0,
    'password' => null,
];

$redisCache = new RedisCache('namespace', $redisConfig, 3); // namespace, config, max retries

// Memcached cache with custom config
$memcachedConfig = [
    'servers' => [
        ['127.0.0.1', 11211, 100]
    ],
    'persistent_id' => 'my_app',
    'binary_protocol' => true,
    'compression' => true,
];
$memcachedCache = new MemcachedCache('namespace', $memcachedConfig, 3);
```

## Usage

### Basic Usage

#### Direct Instantiation

```php
use Quellabs\Cache\FileCache;
use Quellabs\Cache\RedisCache;
use Quellabs\Cache\MemcachedCache;

// File cache - simple instantiation
$cache = new FileCache('pages', 5); // namespace, lock timeout

// Redis cache with configuration
$redisConfig = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0,
];
$cache = new RedisCache('pages', $redisConfig, 3); // namespace, config, max retries

// Memcached cache with configuration
$memcachedConfig = [
    'servers' => [['127.0.0.1', 11211, 100]],
    'persistent_id' => 'my_app',
];
$cache = new MemcachedCache('pages', $memcachedConfig, 3);

// All drivers support the same interface
$cache->set('user:123', $userData, 3600); // TTL: 1 hour
$user = $cache->get('user:123', $defaultUser);

if ($cache->has('user:123')) {
    // Key exists
}

$cache->forget('user:123');
$cache->flush(); // Clear all items in namespace
```

### Cache-Aside Pattern

The `remember` method implements the cache-aside pattern with concurrency protection:

```php
$expensiveData = $cache->remember('report:monthly', 3600, function() {
    // This callback only runs on cache miss
    return generateMonthlyReport();
});
```

### Dependency Injection

Use the cache through dependency injection with automatic provider resolution. The `config/cache.php` file is used to determine which cache driver to inject:

```php
use Quellabs\Contracts\Cache\CacheInterface;

class UserService {
    
    public function __construct(
        private CacheInterface $cache // Injected based on config/cache.php
    ) {}
    
    public function getUser(int $id): User {
        return $this->cache->remember("user:{$id}", 300, function() use ($id) {
            return $this->database->findUser($id);
        });
    }
}
```

#### Method-Level Cache Context

For method-specific cache configuration, the `@CacheContext` annotation works with method-level dependency injection:

```php
use Quellabs\Canvas\Annotations\CacheContext;

class UserService {
    
    /**
     * @CacheContext(namespace="users", driver="redis")
     */
    public function getUser(int $id, CacheInterface $cache): User {
        return $cache->remember("user:{$id}", 300, function() use ($id) {
            return $this->database->findUser($id);
        });
    }
    
    /**
     * @CacheContext(namespace="sessions", driver="memcached")  
     */
    public function getUserSessions(int $userId, CacheInterface $cache): array {
        return $cache->remember("sessions:{$userId}", 600, function() use ($userId) {
            return $this->database->findUserSessions($userId);
        });
    }
}
```

### Controller Integration

Integrate with aspect-oriented programming for automatic caching:

```php
use Quellabs\Canvas\Annotations\Route;
use Quellabs\Canvas\Annotations\InterceptWith;
use Quellabs\Canvas\Cache\CacheAspect;

class BlogController extends BaseController {
    
    /**
     * @Route("/posts/")
     * @InterceptWith(CacheAspect::class)
     */
    public function index(): Response {
        $posts = $this->em->findBy(PostEntity::class, ['published' => true]);
        
        return $this->render("blog/index.tpl", [
            'posts' => $posts
        ]);
    }
}
```

## Cache Drivers

### FileCache

Thread-safe file-based caching with atomic operations:

- **Pros**: No external dependencies, persistent across server restarts
- **Cons**: Slower than memory-based solutions, not suitable for distributed systems
- **Best for**: Development, small applications, persistent caching needs

```php
$cache = new FileCache('namespace', 5); // 5-second lock timeout
```

### RedisCache

High-performance Redis-based caching:

- **Pros**: Excellent performance, built-in data structures, pub/sub support
- **Cons**: Requires Redis server, data lost on restart (unless configured for persistence)
- **Best for**: High-traffic applications, session storage, real-time features

```php
$config = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0,
];
$cache = new RedisCache('namespace', $config, 3);
```

### MemcachedCache

Distributed memory caching with multi-server support:

- **Pros**: Distributed architecture, automatic failover, efficient memory usage
- **Cons**: Requires Memcached servers, data lost on restart
- **Best for**: Distributed applications, high-availability requirements

```php
$config = [
    'servers' => [
        ['server1.example.com', 11211, 100],
        ['server2.example.com', 11211, 100],
    ],
    'persistent_id' => 'my_app',
];
$cache = new MemcachedCache('namespace', $config, 3);
```

## Advanced Features

### Monitoring & Statistics

All cache drivers provide statistics for monitoring:

```php
$stats = $cache->getStats();
print_r($stats);

// Example output:
// Array(
//     [namespace] => users
//     [hit_ratio] => 85.2
//     [total_hits] => 1247
//     [total_misses] => 218
//     [memory_usage] => 45.2MB
// )
```

### Namespace Isolation

Use namespaces to organize cache data and prevent key collisions:

```php
$userCache = new RedisCache('users');
$sessionCache = new RedisCache('sessions');

// These won't conflict
$userCache->set('123', $userData);
$sessionCache->set('123', $sessionData);
```

### Error Handling

All cache operations are designed to fail gracefully:

```php
// Cache operations never throw exceptions in normal usage
$value = $cache->get('key', 'default'); // Returns 'default' if cache fails
$success = $cache->set('key', 'value'); // Returns false if operation fails
```

## Concurrency & Thread Safety

### File Cache Concurrency

- **Atomic Writes**: Uses temporary files + atomic rename operations
- **Reader/Writer Locks**: Prevents corruption during concurrent access
- **Process Locks**: Coordinates expensive operations across processes
- **Race Condition Handling**: Safe directory creation and file deletion

### Redis Cache Concurrency

- **Single-Threaded Redis**: Leverages Redis's natural atomicity
- **Connection Pooling**: Efficient connection management
- **Automatic Reconnection**: Handles connection failures gracefully

### Memcached Cache Concurrency

- **Consistent Hashing**: Distributed key placement across servers
- **Connection Pooling**: Persistent connections for performance
- **Failover Support**: Automatic server failure detection and recovery

## Performance Considerations

### File Cache

- Stores cache files in `storage/cache/{namespace}/` directory
- Uses SHA-256 hashed filenames for filesystem safety
- Implements lock timeouts to prevent deadlocks
- Automatically cleans up expired cache files

### Redis Cache

- Uses binary-safe serialization for complex data types
- Leverages Redis's native TTL support for automatic expiration
- Implements exponential backoff for retry logic
- Connection pooling reduces overhead

### Memcached Cache

- Supports compression for large values (configurable threshold)
- Uses binary protocol for improved performance
- Implements consistent hashing for distributed setups
- Automatic server weight balancing

## Troubleshooting

### Common Issues

**File Permissions**
```bash
# Ensure cache directory is writable
chmod -R 755 storage/cache/
chown -R www-data:www-data storage/cache/
```

**Redis Connection Issues**
```bash
# Check Redis is running
redis-cli ping

# Check Redis configuration
redis-cli info
```

**Memcached Connection Issues**
```bash
# Check Memcached is running
telnet localhost 11211

# Test Memcached stats
echo "stats" | nc localhost 11211
```

### Performance Tuning

**File Cache**
- Use SSD storage for better I/O performance
- Consider RAM disk for temporary caches
- Monitor disk space usage

**Redis Cache**
- Configure appropriate `maxmemory` settings
- Use Redis persistence settings based on your needs
- Monitor Redis memory usage and fragmentation

**Memcached Cache**
- Tune memory allocation per server
- Monitor connection limits
- Consider server placement for network latency

## License

This project is licensed under the MIT License.