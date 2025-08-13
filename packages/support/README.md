# Quellabs Support

A comprehensive support utilities library for the Quellabs ecosystem, providing essential tools for Composer path resolution, class discovery, debugging, framework detection, and string manipulation.

## Features

- **Enhanced PHP Debugging** - Sophisticated variable dumping with collapsible HTML output and colored terminal support
- **Composer Utilities** - Advanced path resolution, namespace detection, and PSR-4 class discovery
- **Framework Detection** - Automatic detection of popular PHP frameworks
- **String Inflection** - Pluralization and singularization of English words
- **Multi-Environment Support** - Works seamlessly in web browsers, CLI, and various hosting environments

## Requirements

- PHP 8.2 or higher

## Installation

Install via Composer:

```bash
composer require quellabs/support
```

## Components

### CanvasDebugger

Enhanced debugging utility that provides better variable visualization than standard `var_dump()`.

**Features:**
- Rich HTML output for web contexts with syntax highlighting
- Colored terminal output for CLI environments
- Collapsible interface for complex data structures
- Call location tracking
- Graceful fallbacks when headers are already sent

**Usage:**
```php
use Quellabs\Support\CanvasDebugger;

// Dump variables
CanvasDebugger::dump($var1, $var2, $var3);

// Dump and terminate execution
CanvasDebugger::dumpAndDie($variable);

// Or use the global helpers (recommended)
d($variable);           // dump
dd($variable);          // dump and die
```

### ComposerUtils

Comprehensive utilities for working with Composer projects, PSR-4 namespaces, and project structure detection.

**Key Features:**
- **Project Root Detection** - Intelligently finds project root using multiple strategies
- **Namespace Resolution** - Maps directory paths to PSR-4 namespaces
- **Class Discovery** - Recursively scans directories to find all classes
- **Hosting Environment Support** - Optimized for shared hosting (cPanel, Plesk, etc.)
- **Performance Optimized** - Extensive caching to minimize filesystem operations

**Usage:**
```php
use Quellabs\Support\ComposerUtils;

// Find project root directory
$projectRoot = ComposerUtils::getProjectRoot();

// Get namespace for a directory
$namespace = ComposerUtils::resolveNamespaceFromPath('/path/to/directory');

// Find all classes in a directory
$classes = ComposerUtils::findClassesInDirectory('/path/to/src');

// Get Composer autoloader instance
$autoloader = ComposerUtils::getComposerAutoloader();

// Resolve project-relative paths
$absolutePath = ComposerUtils::resolveProjectPath('src/Models');

// Find Composer files
$composerJson = ComposerUtils::getComposerJsonFilePath();
$installedJson = ComposerUtils::getComposerInstalledJsonPath();
```

**Advanced Examples:**
```php
// Find classes with filtering
$controllers = ComposerUtils::findClassesInDirectory(
    '/path/to/src/Controllers',
    fn($class) => str_ends_with($class, 'Controller')
);

// Normalize paths with relative components
$normalized = ComposerUtils::normalizePath('../config/app.php');

// Clear caches (useful for testing)
ComposerUtils::clearCache();
```

### FrameworkDetector

Automatically detects which PHP framework is currently being used by checking for framework-specific classes.

**Supported Frameworks:**
- Canvas (Quellabs)
- Laravel
- Symfony
- CakePHP
- CodeIgniter
- Laminas/Zend Framework
- Yii
- Phalcon
- Slim

**Usage:**
```php
use Quellabs\Support\FrameworkDetector;

$framework = FrameworkDetector::detect();
// Returns: 'canvas', 'laravel', 'symfony', 'cakephp', 'codeigniter', 
//          'laminas', 'yii', 'phalcon', 'slim', or 'unknown'

// Framework-specific logic
switch (FrameworkDetector::detect()) {
    case 'laravel':
        // Laravel-specific code
        break;
    case 'symfony':
        // Symfony-specific code
        break;
    default:
        // Generic code
}
```

### StringInflector

Utility for converting English words between singular and plural forms with comprehensive rule support.

**Features:**
- Handles irregular words (person → people, child → children)
- Supports uncountable nouns (equipment, information, sheep)
- Comprehensive pluralization rules
- Case preservation
- Plurality detection

**Usage:**
```php
use Quellabs\Support\StringInflector;

// Pluralization
echo StringInflector::pluralize('user');     // users
echo StringInflector::pluralize('child');    // children
echo StringInflector::pluralize('Category'); // Categories

// Singularization  
echo StringInflector::singularize('users');      // user
echo StringInflector::singularize('children');   // child
echo StringInflector::singularize('Categories'); // Category

// Plurality checking
StringInflector::isPlural('users');    // true
StringInflector::isSingular('user');   // true
StringInflector::isPlural('sheep');    // false (uncountable)
```

**Supported Patterns:**
- Regular patterns: cat → cats, box → boxes
- Y endings: city → cities, boy → boys
- F/FE endings: knife → knives, half → halves
- O endings: hero → heroes, radio → radios
- Irregular forms: person → people, datum → data
- Uncountable: sheep, equipment, information

## Environment Support

The library is optimized for various hosting environments:

- **Shared Hosting** - Special detection for cPanel, Plesk, DirectAdmin
- **Cloud Platforms** - Works with containerized environments
- **Development** - Local development with XAMPP, MAMP, etc.
- **CLI Applications** - Full command-line interface support

## Global Helper Functions

When the library is installed via Composer, these global functions are automatically available:

```php
// Debug helpers (defined in bootstrap.php)
d($var1, $var2);        // Enhanced dump
dd($variable);          // Dump and die
```

## Error Handling

All components include comprehensive error handling:

- Graceful fallbacks when optimal features aren't available
- Protection against headers already sent errors
- Safe handling of missing files or directories
- Clear error messages for debugging

## Performance

The library is designed for production use with:

- **Extensive Caching** - Results cached to avoid repeated operations
- **Lazy Loading** - Components loaded only when needed
- **Optimized Detection** - Multiple strategies with fallbacks
- **Memory Efficiency** - Minimal memory footprint

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Submit a pull request

## License

This library is licensed under the MIT License. See LICENSE file for details.