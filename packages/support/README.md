# Quellabs Support

A comprehensive PHP support library providing essential utilities for the Quellabs ecosystem, including advanced debugging, Composer integration, namespace resolution, and framework detection.

## Features

- **🐛 Advanced Debugging** - Enhanced variable dumping with rich HTML output and colored CLI display
- **📦 Composer Integration** - Intelligent project root detection and PSR-4 namespace resolution
- **🔍 Context Resolution** - Smart call stack analysis to determine execution context
- **🏗️ Framework Detection** - Automatic detection of popular PHP frameworks
- **📝 Namespace Resolution** - PHP-compliant class name resolution with use statement parsing
- **📚 String Inflection** - English pluralization and singularization utilities

## Requirements

- PHP 8.2 or higher
- Composer for autoloading

## Installation

```bash
composer require quellabs/support
```

## Quick Start

The library automatically registers global debugging functions when installed, providing an **enhanced alternative to Laravel's `dd()`** with rich formatting and intelligent context detection:

```php
// Simple variable dumping (like Laravel's dump())
d($user, $request->all(), $config);

// Dump and die (enhanced version of Laravel's dd())
dd($user, $request->all(), $config);
```

## Core Components

### CanvasDebugger

The core debugging engine that powers the global `d()` and `dd()` functions. While you'll typically use the convenient global functions, the class can be called directly when needed:

```php
use Quellabs\Support\CanvasDebugger;

// Equivalent to d($data, $moreData)
CanvasDebugger::dump($data, $moreData);

// Equivalent to dd($criticalData) 
CanvasDebugger::dumpAndDie($criticalData);

// Direct usage in classes where global functions might conflict
class MyDebugger {
    public function debug($data) {
        CanvasDebugger::dump($data);
    }
}
```

**Features:**
- Rich HTML output for web contexts with syntax highlighting
- Colored terminal output for CLI environments
- Collapsible sections for large data structures
- Call location tracking
- Graceful fallback handling

### ComposerUtils

Comprehensive Composer project analysis and PSR-4 utilities:

```php
use Quellabs\Support\ComposerUtils;

// Find project root directory
$projectRoot = ComposerUtils::getProjectRoot();

// Get composer.json file path
$composerJson = ComposerUtils::getComposerJsonFilePath();

// Resolve namespace from directory path
$namespace = ComposerUtils::resolveNamespaceFromPath('/path/to/src/Models');
// Returns: "App\Models"

// Find all classes in a directory
$classes = ComposerUtils::findClassesInDirectory('/path/to/controllers');
// Returns: ["App\Controllers\UserController", "App\Controllers\PostController"]

// Normalize file paths
$normalizedPath = ComposerUtils::normalizePath('some/../complex/./path');
// Returns: "complex/path"
```

**Capabilities:**
- Intelligent project root detection for various hosting environments
- PSR-4 namespace mapping and resolution
- Recursive class discovery with filtering
- Path normalization and resolution
- Shared hosting environment support (cPanel, Plesk, DirectAdmin, etc.)

### NamespaceResolver

PHP-compliant class name resolution that mimics PHP's native resolution behavior:

```php
use Quellabs\Support\NamespaceResolver;

// Resolve class name based on current context
$resolvedClass = NamespaceResolver::resolveClassName('User');
// Returns: "App\Models\User" (based on use statements and current namespace)

// Resolve with specific context
$reflection = new ReflectionClass('App\Services\UserService');
$resolvedClass = NamespaceResolver::resolveClassName('User', $reflection);
```

**Resolution Strategy:**
1. Direct import matches (`use App\Models\User;`)
2. Compound name resolution (`Models\User` with `use App\Models;`)
3. Current namespace resolution
4. Global namespace fallback

### FrameworkResolver

Automatic detection of popular PHP frameworks:

```php
use Quellabs\Support\FrameworkResolver;

$framework = FrameworkResolver::detect();
// Returns: 'laravel', 'symfony', 'canvas', 'cakephp', etc.
```

**Supported Frameworks:**
- Canvas
- Laravel
- Symfony
- CakePHP
- CodeIgniter
- Zend/Laminas
- Yii
- Phalcon
- Slim

### ContextResolver

Intelligent call stack analysis to find non-framework calling context:

```php
use Quellabs\Support\ContextResolver;

$context = ContextResolver::getCallingContext();
// Returns: ['file' => '...', 'class' => '...', 'function' => '...', 'line' => ...]
```

### StringInflector

English word pluralization and singularization:

```php
use Quellabs\Support\StringInflector;

// Pluralization
echo StringInflector::pluralize('user');     // "users"
echo StringInflector::pluralize('child');    // "children"
echo StringInflector::pluralize('person');   // "people"

// Singularization
echo StringInflector::singularize('users');     // "user"
echo StringInflector::singularize('children');  // "child"
echo StringInflector::singularize('people');    // "person"

// Form checking
StringInflector::isPlural('users');    // true
StringInflector::isSingular('user');   // true
```

**Features:**
- Comprehensive irregular word handling
- Uncountable noun support
- Case preservation
- Advanced pluralization rules

### UseStatementParser

Extracts and parses PHP use statements from class files:

```php
use Quellabs\Support\UseStatementParser;

$reflection = new ReflectionClass('App\Services\UserService');
$imports = UseStatementParser::getImportsForClass($reflection);
// Returns: ['User' => 'App\Models\User', 'Request' => 'Illuminate\Http\Request']
```

**Capabilities:**
- Single and grouped use statement parsing
- Alias resolution
- Efficient caching
- PSR-4 compatibility

## Performance Considerations

The library includes several performance optimizations:

- **Caching**: All expensive operations (file parsing, reflection, etc.) are cached
- **Lazy Loading**: Components are loaded only when needed
- **Memory Management**: Automatic cache size limits prevent memory issues
- **Optimized Algorithms**: Efficient path resolution and namespace matching

## Error Handling

The library provides graceful error handling:

- File system errors fall back to alternative detection methods
- Invalid composer.json files are handled gracefully
- Missing classes don't break namespace resolution
- Debug output includes fallback mechanisms

## Contributing

This library is part of the Quellabs ecosystem. Contributions should follow PSR-12 coding standards and include comprehensive tests.

## License

MIT License. See LICENSE file for details.