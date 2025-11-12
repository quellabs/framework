# Quellabs Discover

[![PHP Version](https://img.shields.io/packagist/php-v/quellabs/discover.svg)](https://packagist.org/packages/quellabs/discover)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/quellabs/discover.svg)](https://packagist.org/packages/quellabs/discover)
[![License](https://img.shields.io/github/license/quellabs/discover.svg)](https://github.com/quellabs/discover/blob/master/LICENSE.md)

A lightweight, flexible service discovery component for PHP applications that automatically discovers service providers across your application and its dependencies with advanced caching and lazy loading capabilities.

## Introduction

Quellabs Discover solves the common challenge of service discovery in PHP applications. It focuses solely on locating service providers defined in your application and its dependencies, giving you complete control over how to use these providers in your application architecture. Unlike other service discovery solutions that force specific patterns, Discover is framework-agnostic and can be integrated into any PHP application.

**Key Features:**
- **Framework Agnostic**: Works with any PHP application or framework
- **Multiple Discovery Methods**: Composer configuration, directory scanning, and custom scanners
- **Provider Families**: Organize providers into logical groups
- **Fluent Query Builder**: Chainable API for filtering providers
- **Efficient Discovery**: Uses static methods to gather metadata without instantiation
- **Efficient Caching**: Export and import provider definitions for fast subsequent loads
- **Lazy Instantiation**: Providers are only created when actually needed

## Installation

Install the package via Composer:

```bash
composer require quellabs/discover
```

## Quick Start

Here's how to quickly get started with Discover:

```php
use Quellabs\Discover\Discover;
use Quellabs\Discover\Scanner\ComposerScanner;
use Quellabs\Discover\Scanner\DirectoryScanner;

// Create a Discover instance
$discover = new Discover();

// Configure scanners to discover providers
$discover->addScanner(new ComposerScanner());
$discover->addScanner(new DirectoryScanner([
    __DIR__ . '/app/Providers'
], '/Provider$/'));

// Run the discovery process (gathers metadata without instantiation)
$discover->discover();

// Use the fluent query builder to find specific providers
$cacheProviders = $discover->findProviders()
    ->withCapability('redis')
    ->withMinPriority(5)
    ->get();

foreach ($cacheProviders as $provider) {
    // Register with your container or use directly
    $yourContainer->register($provider);
}
```

## Service Providers

### Creating a Service Provider

To create a discoverable service provider, implement the `ProviderInterface`:

```php
<?php

namespace App\Providers;

use Quellabs\Discover\Provider\AbstractProvider;

class ExampleServiceProvider extends AbstractProvider {

    /**
     * Get metadata about this provider's capabilities (static method)
     * @return array<string, mixed>
     */
    public static function getMetadata(): array {
        return [
            'capabilities' => ['redis', 'clustering'],
            'version'      => '1.0.0',
            'priority'     => 10
        ];
    }
    
    /**
     * Get default configuration values (static method)
     * @return array
     */
    public static function getDefaults(): array {
        return [
            'host'    => 'localhost',
            'port'    => 6379,
            'timeout' => 2.5
        ];
    }
}
```

### Provider Interface

The core `ProviderInterface` separates discovery-time methods (static) from runtime methods (instance):

```php
interface ProviderInterface {
    
    // Static methods for discovery (no instantiation needed)
    public static function getMetadata(): array;
    public static function getDefaults(): array;
    
    // Instance methods for runtime configuration
    public function setConfig(array $config): void;
    public function getConfig(): array;
}
```

This interface specifies:
1. **Static discovery methods** - Called during discovery without instantiation
2. **Instance configuration methods** - Used when providers are actually needed

The actual implementation of how services are created and used is left to your application.

## Discovery Methods

Quellabs Discover supports multiple methods to discover service providers:

### Composer Configuration

Add service providers to your `composer.json` file using the nested structure where `discover` is always the top-level key:

```json
{
  "name": "your/package",
  "extra": {
    "discover": {
      "default": {
        "providers": [
          "App\\Providers\\ExampleServiceProvider",
          "App\\Providers\\AnotherServiceProvider"
        ]
      }
    }
  }
}
```

Use the `ComposerScanner` to discover these providers:

```php
$discover->addScanner(new ComposerScanner('default'));
```

### Directory Scanning

Scan directories for provider classes:

```php
$discover->addScanner(new DirectoryScanner([
    __DIR__ . '/app/Providers',
    __DIR__ . '/src/Providers'
], '/Provider$/', 'cache')); // Pattern and family name
```

## Querying Providers

### Fluent Query Builder

The `findProviders()` method returns a query builder that allows you to chain filter methods for expressive, readable queries:

```php
// Find Redis providers with high priority
$providers = $discover->findProviders()
    ->withCapability('redis')
    ->withMinPriority(10)
    ->get();

// Find database providers in a specific family
$dbProviders = $discover->findProviders()
    ->withFamily('database')
    ->get();

// Combine multiple filters
$providers = $discover->findProviders()
    ->withCapability('clustering')
    ->withFamily('cache')
    ->withMinPriority(5)
    ->get();
```

### Query Methods

The query builder provides several convenience methods for common filtering patterns:

#### `withCapability(string $capability)`

Filters providers that declare a specific capability in their metadata:

```php
$redisProviders = $discover->findProviders()
    ->withCapability('redis')
    ->get();
```

This checks for providers with metadata like:
```php
public static function getMetadata(): array {
    return ['capabilities' => ['redis', 'clustering']];
}
```

#### `withMinPriority(int $priority)`

Filters providers with a priority value greater than or equal to the specified minimum:

```php
$highPriorityProviders = $discover->findProviders()
    ->withMinPriority(10)
    ->get();
```

#### `withFamily(string $family)`

Filters providers belonging to a specific family:

```php
$cacheProviders = $discover->findProviders()
    ->withFamily('cache')
    ->get();
```

### Retrieving Results

The query builder provides two methods for retrieving results:

#### `get(): array`

Returns all matching providers as an array. Best for small result sets:

```php
$providers = $discover->findProviders()
    ->withCapability('redis')
    ->get();

// Process all providers at once
foreach ($providers as $provider) {
    $container->register($provider);
}
```

#### `lazy(): \Generator`

Returns a generator that instantiates providers one at a time. More memory-efficient for large result sets:

```php
$providers = $discover->findProviders()
    ->withFamily('database')
    ->lazy();

// Providers are instantiated one at a time
foreach ($providers as $provider) {
    // Process each provider as it's instantiated
    $container->register($provider);
}
```

### Custom Filters

For more complex filtering logic, use the `where()` method with a custom closure:

```php
// Find providers with specific version requirements
$providers = $discover->findProviders()
    ->where(function($metadata) {
        return isset($metadata['version']) && 
               version_compare($metadata['version'], '2.0.0', '>=');
    })
    ->get();

// Combine built-in methods with custom filters
$providers = $discover->findProviders()
    ->withFamily('cache')
    ->where(function($metadata) {
        return isset($metadata['region']) && 
               $metadata['region'] === 'us-east-1';
    })
    ->get();
```

### Direct Access Methods

For simple lookups without the query builder:

```php
// Get a specific provider by class name (O(1) lookup)
$provider = $discover->get('App\\Providers\\RedisProvider');

// Check if a provider exists
if ($discover->exists('App\\Providers\\RedisProvider')) {
    // Provider is available
}

// Get the definition for a provider (metadata without instantiation)
$definition = $discover->getDefinition('App\\Providers\\RedisProvider');

// Get all providers (warning: instantiates everything)
foreach ($discover->getProviders() as $provider) {
    // Use provider
}
```

## Caching and Performance

Quellabs Discover includes sophisticated caching mechanisms to dramatically improve performance, especially in production environments.

### Provider Definition Caching

The discovery process gathers provider metadata using static methods without instantiation. This is already efficient, but you can cache the gathered definitions for even better performance.

#### Exporting Cache Data

After running discovery, export the provider definitions for caching:

```php
// Perform discovery (gathers metadata using static methods - no instantiation)
$discover = new Discover();
$discover->addScanner(new ComposerScanner());
$discover->addScanner(new DirectoryScanner([__DIR__ . '/app/Providers']));
$discover->discover();

// Export definitions for caching
$cacheData = $discover->exportForCache();

// Store in your preferred cache system
file_put_contents('cache/providers.json', json_encode($cacheData));
// Or use Redis, Memcached, etc.
$redis->set('app:providers', serialize($cacheData));
```

#### Importing from Cache

On subsequent requests, bypass the discovery process entirely:

```php
// Load from cache
$cacheData = json_decode(file_get_contents('cache/providers.json'), true);
// Or from Redis: $cacheData = unserialize($redis->get('app:providers'));

// Import cached definitions (no scanning or static method calls needed)
$discover = new Discover();
$discover->importDefinitionsFromCache($cacheData);

// Providers are now available without running discovery!
$providers = $discover->findProviders()
    ->withFamily('database')
    ->get();
```

#### Understanding Access Patterns

```php
// ⚠️ BULK ACCESS: Instantiates all providers
$allProviders = $discover->getProviders(); // Use when you need everything

// ✅ FILTERED ACCESS: Only instantiates matching providers
$specificProviders = $discover->findProviders()
    ->withCapability('redis')
    ->get();

// ✅ LAZY ACCESS: Memory-efficient for large sets
foreach ($discover->findProviders()->withFamily('cache')->lazy() as $provider) {
    // Process one at a time
}

// ✅ METADATA ONLY: No instantiation at all
$definition = $discover->getDefinition('App\\Providers\\RedisProvider');
```

### Performance Best Practices

#### 1. Use Caching in Production

```php
// Development: Always discover fresh for changes
if ($app->environment('development')) {
    $discover->discover();
} else {
    // Production: Use cache with version-based invalidation
    $cacheKey = 'providers_' . md5_file('composer.lock');
    $cached = $cache->get($cacheKey);
    
    if ($cached) {
        $discover->importDefinitionsFromCache($cached);
    } else {
        $discover->discover();
        $cache->set($cacheKey, $discover->exportForCache());
    }
}
```

#### 2. Use Query Builder for Selective Loading

```php
// ❌ DON'T: Load all providers when you only need some
$allProviders = $discover->getProviders();
$cacheProviders = array_filter($allProviders, fn($p) => /* ... */);

// ✅ DO: Use query builder to load only what you need
$cacheProviders = $discover->findProviders()
    ->withCapability('cache')
    ->get();
```

#### 3. Use lazy() for Large Result Sets

```php
// ❌ DON'T: Load thousands of providers into memory at once
$providers = $discover->findProviders()->withFamily('plugins')->get();

// ✅ DO: Process providers one at a time
foreach ($discover->findProviders()->withFamily('plugins')->lazy() as $provider) {
    $container->register($provider);
}
```

#### 4. Cache Individual Providers

Provider instances are automatically cached after first instantiation:

```php
// First call: instantiates the provider
$redis = $discover->get('App\\Providers\\RedisProvider');

// Subsequent calls: returns cached instance
$redis = $discover->get('App\\Providers\\RedisProvider'); // No re-instantiation
```

## Provider Configuration

Quellabs Discover supports configuration files for providers registered through Composer.

### Basic Configuration File

Create a configuration file that returns an array:

```php
// config/providers/example.php
return [
    'option1' => 'value1',
    'option2' => 'value2',
    'enabled' => true,
    // Any configuration your provider needs
];
```

### Registering Provider with Configuration

Specify a configuration file in your `composer.json`:

```json
{
  "extra": {
    "discover": {
      "default": {
        "providers": [
          {
            "class": "App\\Providers\\ExampleServiceProvider",
            "config": "config/providers/example.php"
          },
          {
            "class": "App\\Providers\\AnotherServiceProvider",
            "config": "config/providers/another.php"
          }
        ]
      }
    }
  }
}
```

### Using Configuration in Providers

Configuration is loaded and merged with defaults when providers are instantiated:

```php
class ExampleServiceProvider extends \Quellabs\Discover\Provider\AbstractProvider {

    public static function getDefaults(): array {
        return [
            'option1' => 'default_value',
            'option2' => 'default_value',
            'enabled' => false
        ];
    }

    public function getServiceOptions(): array {
        return [
            'option1' => $this->config['option1'],
            'option2' => $this->config['option2'],
        ];
    }
}
```

## Provider Families

Provider families organize service providers into logical groups. Families are determined by the composer.json structure, not by the provider classes themselves.

### Defining Provider Families

Define providers in different families in your `composer.json`:

```json
{
  "extra": {
    "discover": {
      "database": {
        "providers": [
          "App\\Providers\\MySQLProvider",
          "App\\Providers\\PostgreSQLProvider"
        ]
      },
      "cache": {
        "providers": [
          "App\\Providers\\RedisProvider",
          "App\\Providers\\MemcachedProvider"
        ]
      }
    }
  }
}
```

### Using Multiple Family Scanners

Create scanners for each family:

```php
$discover = new Discover();
$discover->addScanner(new ComposerScanner('database'));
$discover->addScanner(new ComposerScanner('cache'));
$discover->discover();

// Query by family
$databaseProviders = $discover->findProviders()
    ->withFamily('database')
    ->get();

$cacheProviders = $discover->findProviders()
    ->withFamily('cache')
    ->get();
```

## Framework Integration

### Integration with Canvas

```php
// In your Canvas bootstrap file
use Quellabs\Canvas\Container;
use Quellabs\Discover\Discover;

$discover = new Discover();
$discover->addScanner(new ComposerScanner());
$discover->discover();

$container = new Container();
foreach ($discover->getProviders() as $provider) {
    $container->register($provider);
}
```

### Production Optimization Example

```php
// In your application bootstrap
class ApplicationBootstrap {
    public function initializeProviders(): Discover {
        $discover = new Discover();
        
        // Check if we have valid cached provider definitions
        $cacheKey = 'app_providers_' . md5(filemtime('composer.lock'));
        $cached = $this->cache->get($cacheKey);
        
        if ($cached && $this->isProduction()) {
            // Use cached definitions in production (no scanning needed)
            $discover->importDefinitionsFromCache($cached);
        } else {
            // Perform discovery and cache results
            $discover->addScanner(new ComposerScanner());
            $discover->addScanner(new DirectoryScanner([__DIR__ . '/app/Providers']));
            $discover->discover();
            
            // Cache gathered provider information for future requests
            $this->cache->set($cacheKey, $discover->exportForCache(), 3600);
        }
        
        return $discover;
    }
}
```

## Extending Discover

### Creating Custom Scanners

Implement the `ScannerInterface` to create custom scanners:

```php
<?php

namespace App\Discovery;

use Quellabs\Contracts\Discovery\ProviderDefinition;
use Quellabs\Discover\Scanner\ScannerInterface;

class CustomScanner implements ScannerInterface {
    public function scan(): array {
        // Your custom discovery logic
        // Return an array of ProviderDefinition objects
        return [
            new ProviderDefinition(
                className: 'App\\Providers\\CustomProvider',
                family: 'custom',
                configFiles: ['config/custom.php'],
                metadata: ['capability' => 'special'],
                defaults: ['enabled' => true]
            )
        ];
    }
}
```

## License

The Quellabs Discover package is open-sourced software licensed under the [MIT license](https://github.com/quellabs/discover/blob/master/LICENSE.md).