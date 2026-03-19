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
}
```

### Provider Interface

The core `ProviderInterface` separates discovery-time methods (static) from runtime methods (instance):

```php
interface ProviderInterface {
    
    // Static methods for discovery (no instantiation needed)
    public static function getMetadata(): array;
    
    // Instance methods for runtime configuration
    public function setConfig(array $config): void;
    public function getConfig(): array;
}
```

Static methods are called during discovery without instantiation; instance methods are used at runtime when providers are actually needed.

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

## Metadata Scanning

In addition to discovering service providers, Quellabs Discover can collect arbitrary key/value metadata that packages advertise in their `composer.json`. This is useful when packages need to expose configuration paths, resource directories, or other structured data to the host application — without registering a full service provider.

Provider scanning and metadata scanning are separate systems that run in the same `discover()` call. A single `Discover` instance can run both simultaneously.

### How It Works

Metadata scanners read from the `extra.discover.<family>` section of every installed package's `composer.json`. Each package can declare any number of keys under the family. Values can be a scalar string or an array of strings — they are returned as-is without transformation.

```json
{
  "name": "vendor/my-package",
  "extra": {
    "discover": {
      "canvas": {
        "controllers": ["src/Controllers", "src/Api"],
        "middleware": "src/Middleware"
      }
    }
  }
}
```

### MetadataScannerInterface

Custom metadata scanners implement `MetadataScannerInterface`, which requires two methods:

```php
interface MetadataScannerInterface {
    // The family name this scanner reads from (e.g. 'canvas')
    public function getFamilyName(): string;

    // Returns all metadata for this family, grouped by package name
    // e.g. ['vendor/pkg' => ['controllers' => 'src/Controllers', ...]]
    public function collect(): array;
}
```

Unlike `ScannerInterface` (which produces `ProviderDefinition` objects), metadata scanners return raw values. Results are grouped by package name to preserve the relationship between keys declared together in the same `composer.json`.

### Using MetadataCollector

`MetadataCollector` is the built-in implementation. Register it via the same `addScanner()` method used for provider scanners — `Discover` automatically routes it to the metadata pipeline based on the interface it implements:

```php
use Quellabs\Discover\Discover;
use Quellabs\Discover\Scanner\ComposerScanner;
use Quellabs\Discover\Scanner\MetadataCollector;

$discover = new Discover();

// Provider scanner and metadata scanner can coexist
$discover->addScanner(new ComposerScanner('canvas'));
$discover->addScanner(new MetadataCollector('canvas'));

$discover->discover();
```

The `MetadataCollector` constructor accepts a few optional parameters:

```php
new MetadataCollector(
    familyName: 'canvas',          // Required: the family to read
    discoverySection: 'discover',  // Optional: top-level key in extra (default: 'discover')
    logger: $logger,               // Optional: PSR-3 logger for non-fatal warnings
    strictMode: false              // Optional: throw exceptions instead of logging warnings
);
```

In strict mode, malformed entries (wrong types, non-array family blocks) throw a `\RuntimeException` instead of being silently skipped.

### Retrieving Collected Metadata

After `discover()` runs, three methods are available for accessing collected metadata:

#### `getAllMetadata(): array`

Returns everything collected, nested as `[family][package][key]`:

```php
$all = $discover->getAllMetadata();
// [
//   'canvas' => [
//     'vendor/controllers-pkg' => ['controllers' => ['src/Controllers', 'src/Api']],
//     'vendor/middleware-pkg'  => ['middleware' => 'src/Middleware'],
//   ]
// ]
```

#### `getFamilyMetadata(string $familyName): array`

Returns all metadata for a single family, grouped by package name. Useful when you need to know which package declared what — for example, to resolve paths relative to each package's install directory:

```php
$canvasMeta = $discover->getFamilyMetadata('canvas');
// [
//   'vendor/controllers-pkg' => ['controllers' => ['src/Controllers', 'src/Api']],
//   'vendor/middleware-pkg'  => ['middleware' => 'src/Middleware'],
// ]

foreach ($canvasMeta as $packageName => $packageData) {
    $dirs = (array)($packageData['controllers'] ?? []);
    // resolve $dirs relative to $packageName's install path
}
```

#### `getFamilyValues(string $familyName, string $metadataKey): array`

Returns a flat, deduplicated list of all values for a specific key across all packages. Handles both scalar values and arrays transparently. This is the most convenient method when you just need a merged list and don't care which package contributed each entry:

```php
$controllerDirs = $discover->getFamilyValues('canvas', 'controllers');
// ['src/Controllers', 'src/Api', 'vendor/other-pkg/src/Controllers']

$middlewareDirs = $discover->getFamilyValues('canvas', 'middleware');
// ['src/Middleware']
```

### Strict Mode and Logging

By default, `MetadataCollector` silently skips malformed entries and logs a warning if a PSR-3 logger is provided. Enable strict mode to surface these issues as exceptions instead:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('discover');
$logger->pushHandler(new StreamHandler('php://stderr'));

// Log warnings but continue on bad entries
$discover->addScanner(new MetadataCollector('canvas', 'discover', $logger));

// Throw on first malformed entry
$discover->addScanner(new MetadataCollector('canvas', 'discover', $logger, strictMode: true));
```

Warnings are emitted for:
- A family block that is not an array (e.g. `"canvas": "invalid"`)
- A metadata value that is neither a string nor an array

### Custom Metadata Scanners

For sources other than `composer.json`, implement `MetadataScannerInterface` directly:

```php
use Quellabs\Discover\Scanner\MetadataScannerInterface;

class DatabaseMetadataScanner implements MetadataScannerInterface {

    public function getFamilyName(): string {
        return 'canvas';
    }

    public function collect(): array {
        // Return data grouped by a logical "package" identifier
        return [
            'app/core' => [
                'controllers' => ['src/Controllers'],
                'middleware'  => ['src/Middleware'],
            ],
        ];
    }
}

$discover->addScanner(new DatabaseMetadataScanner());
$discover->discover();
```

The results are merged with any data collected from `MetadataCollector` under the same family name, so multiple scanners for the same family work transparently.

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

| Method | Description |
|--------|-------------|
| `withCapability(string $capability)` | Matches providers declaring the capability in their `metadata['capabilities']` array |
| `withMinPriority(int $priority)` | Matches providers with `metadata['priority'] >= $priority` |
| `withFamily(string $family)` | Matches providers belonging to the named family |
| `where(callable $fn)` | Custom filter receiving the provider's metadata array |

Use `get()` to return all matches as an array, or `lazy()` to get a generator that instantiates providers one at a time — preferable for large result sets.

```php
// Custom filter example
$providers = $discover->findProviders()
    ->withFamily('cache')
    ->where(fn($meta) => isset($meta['region']) && $meta['region'] === 'us-east-1')
    ->lazy();
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

Provider definitions can be exported after discovery and re-imported on subsequent requests, bypassing the scanning process entirely.

```php
// First request: discover and cache
$discover = new Discover();
$discover->addScanner(new ComposerScanner());
$discover->discover();

$cache->set('providers_' . md5_file('composer.lock'), $discover->exportForCache());

// Subsequent requests: skip discovery
$discover = new Discover();
$discover->importDefinitionsFromCache($cache->get($cacheKey));
```

Provider instances are cached after first instantiation, so repeated calls to `get()` or `findProviders()` for the same class never re-instantiate.


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