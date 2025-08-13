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
- **Efficient Discovery**: Uses static methods to gather metadata without instantiation
- **Efficient Caching**: Export and import provider definitions for lightning-fast subsequent loads
- **Composer Path Resolving**: Built-in tools for namespace and class discovery

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

// Get and use the discovered providers (instantiated on-demand)
$providers = $discover->getProviders();

foreach ($providers as $provider) {
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
$providers = $discover->findProvidersByFamily('database');
```

#### Understanding Access Patterns

```php
// ⚠️ BULK ACCESS: Instantiates all providers
$allProviders = $discover->getProviders(); // Use when you need everything

// ✅ FILTERED ACCESS: Only instantiates matching providers
$specificProviders = $discover->findProvidersByFamily('cache');
$filteredProviders = $discover->findProvidersByMetadata(function($metadata) {
    return isset($metadata['capabilities']) && 
           in_array('redis', $metadata['capabilities']);
});

// ✅ METADATA ONLY: No instantiation at all
$families = $discover->getProviderTypes();
$capabilities = $discover->getAllProviderMetadata();
```

### Performance Best Practices

#### Use Caching in Production

```php
// Development: Always discover fresh for changes
if (app()->environment('local')) {
    $discover->discover();
} else {
    // Production: Use cache to avoid scanning
    $cacheData = $this->cache->get('provider_definitions');
    
    if ($cacheData) {
        $discover->importDefinitionsFromCache($cacheData);
    } else {
        $discover->discover();
        $this->cache->set('provider_definitions', $discover->exportForCache());
    }
}
```

#### Use Filtered Access

```php
// ❌ Don't do this if you only need specific providers
$allProviders = $discover->getProviders(); // Instantiates everything!

// ✅ Do this - get only what you need
$databaseProviders = $discover->findProvidersByFamily('database');
```

#### Optimize Static Methods

Since static methods are called during discovery, keep them lightweight:

```php
class ExampleServiceProvider implements ProviderInterface {
    
    // ✅ Good: Lightweight static methods
    public static function getMetadata(): array {
        return [
            'capabilities' => ['redis'],
            'version' => '1.0.0'
        ];
    }
    
    // ❌ Avoid: Heavy operations in static methods
    public static function getMetadata(): array {
        // Don't do expensive operations here
        $config = file_get_contents('/path/to/config.json'); // This runs during discovery!
        return json_decode($config, true);
    }
    
    // ✅ Better: Keep static methods simple
    public static function getDefaults(): array {
        return [
            'host' => 'localhost',
            'port' => 6379
        ];
    }
}
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
```

### Accessing Providers by Family

Filter providers by family:

```php
// Get all providers of the 'database' family
$databaseProviders = $discover->findProvidersByFamily('database');

// Get all available families
$families = $discover->getProviderTypes();

// Find providers by both family and capability
$redisProviders = $discover->findProvidersByFamilyAndMetadata('cache', function($metadata) {
    return isset($metadata['capabilities']) && 
           in_array('redis', $metadata['capabilities']);
});
```

## PSR-4 Utilities

Quellabs Discover includes utilities for working with PSR-4 namespaces and paths.

### Namespace/Path Mapping

Map between directories and namespaces:

```php
// Get the namespace for a directory
$namespace = $discover->resolveNamespaceFromPath('/path/to/your/project/src/Controllers');
// Returns "App\Controllers" or similar
```

### Finding Classes in Directories

Find classes based on PSR-4 rules:

```php
// Find all controller classes
$controllers = $discover->findClassesInDirectory(
    __DIR__ . '/app/Controllers',
    fn($className) => str_ends_with($className, 'Controller')
);

// Find all classes implementing an interface
$repositories = $discover->findClassesInDirectory(
    __DIR__ . '/app/Repositories',
    fn($className) => class_exists($className) && 
                      is_subclass_of($className, RepositoryInterface::class)
);
```

### Advanced PSR-4 Techniques

Use sophisticated filters for custom class discovery:

```php
// Find only concrete (non-abstract) controller classes with specific methods
$controllers = $discover->findClassesInDirectory(
    __DIR__ . '/app/Controllers',
    function($className) {
        if (!class_exists($className)) {
            return false;
        }
        
        $reflection = new ReflectionClass($className);
        
        return str_ends_with($className, 'Controller') && 
               !$reflection->isAbstract() && 
               $reflection->hasMethod('handle');
    }
);
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
use Quellabs\Discover\Config\DiscoveryConfig;

class CustomScanner implements ScannerInterface {
    public function scan(): array {
        // Your custom discovery logic
        // Return an array of ['class' => $className, 'family' => $family, 'config' => $configFile]
        return [
            new ProviderDefinition(
                className: 'xyz',
				family: 'custom',
				configFile: 'config/config.php',
				metadata: [],
				defaults: []                
            )
        ];
    }
}
```

## License

The Quellabs Discover package is open-sourced software licensed under the [MIT license](https://github.com/quellabs/discover/blob/master/LICENSE.md).