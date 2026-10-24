# PHP Annotation Reader

[![Latest Version](https://img.shields.io/packagist/v/quellabs/annotation-reader.svg)](https://packagist.org/packages/quellabs/signal-hub)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/annotation-reader.svg)](https://packagist.org/packages/quellabs/signal-hub)

A PHP metadata reader for docblock annotations or native PHP attributes.

## Overview

The AnnotationReader component provides robust parsing and caching of PHP docblock annotations, allowing you to define metadata directly within your class docblocks. This approach makes your code more self-documenting and reduces the need for separate configuration files.

## Features

- **Annotation parsing**: Parse docblock annotations for classes, properties, and methods
- **Import resolution**: Automatically resolves class imports for fully qualified annotation names
- **Class constant support**: Supports fully qualified class constants in annotation parameters (e.g., `ObjectName::class`)
- **Performance optimization**: Implements smart caching to improve performance
- **Flexible integration**: Easy to integrate with your existing projects
- **Error handling**: Graceful handling of malformed annotations
- **Collection support**: Returns immutable AnnotationCollection objects with array-like access

## Installation

```bash
composer require quellabs/annotation-reader
```

## Usage

### Basic Usage

```php
use Quellabs\AnnotationReader\AnnotationReader;
use Quellabs\AnnotationReader\Configuration;

// Create configuration
$config = new Configuration();
$config->setAnnotationCachePath(__DIR__ . '/cache');
$config->setUseAnnotationCache(true);

// Create annotation reader
$reader = new AnnotationReader($config);

// Get annotations for a class
$classAnnotations = $reader->getClassAnnotations(MyClass::class);

// Get annotations for a class, filtered by a specific annotation
$classAnnotations = $reader->getClassAnnotations(MyClass::class, SomeAnnotation::class);

// Get annotations for a property
$propertyAnnotations = $reader->getPropertyAnnotations(MyClass::class, 'propertyName');

// Get annotations for a property, filtered by a specific annotation
$propertyAnnotations = $reader->getPropertyAnnotations(MyClass::class, 'propertyName', SomeAnnotation::class);

// Get annotations for a method
$methodAnnotations = $reader->getMethodAnnotations(MyClass::class, 'methodName');

// Get annotations for a method, filtered by a specific annotation
$methodAnnotations = $reader->getMethodAnnotations(MyClass::class, 'methodName', SomeAnnotation::class);
```

### Working with AnnotationCollection

All annotation reader methods return an `AnnotationCollection` object that provides array-like access with a clean, flat structure:

```php
$annotations = $reader->getMethodAnnotations(MyClass::class, 'myMethod');

// Array access by class name - returns first annotation of that type
$interceptor = $annotations[InterceptWith::class];

// Array access by numeric index - returns annotation at that position
$firstAnnotation = $annotations[0];
$secondAnnotation = $annotations[1];

// Iterate through all individual annotations
foreach ($annotations as $annotation) {
    // Each iteration gives you a single annotation object
}

// Get all annotations of a specific type (returns AnnotationCollection)
$allInterceptors = $annotations->all(InterceptWith::class);

// Check if multiple annotations of same type exist
if ($annotations->hasMultiple(InterceptWith::class)) {
    // Process multiple interceptors
}

// Collection methods
$count = count($annotations);
$isEmpty = $annotations->isEmpty();
$firstAnnotation = $annotations->first();
$lastAnnotation = $annotations->last();

// Filtering returns a new AnnotationCollection
$filtered = $annotations->filter(function($annotation) {
    return $annotation->isActive();
});

// Chaining operations
$activeInterceptors = $annotations
    ->all(InterceptWith::class)
    ->filter(fn($interceptor) => $interceptor->isActive());
```

### Handling Multiple Annotations

When you have multiple annotations of the same type, the collection provides clean access patterns:

```php
/**
 * @InterceptWith("AuthValidator")
 * @InterceptWith("LoggingInterceptor") 
 * @Route("/api/users")
 */
public function getUsers() { /* ... */ }
```

```php
$annotations = $reader->getMethodAnnotations(MyClass::class, 'getUsers');

// Get first InterceptWith annotation
$firstInterceptor = $annotations[InterceptWith::class]; 

// Get all InterceptWith annotations as a collection
$allInterceptors = $annotations->all(InterceptWith::class);

// Check for multiple InterceptWith annotations
if ($annotations->hasMultiple(InterceptWith::class)) {
    foreach ($allInterceptors as $interceptor) {
        // Process each interceptor
    }
}

// Get single Route annotation
$route = $annotations[Route::class];

// Iterate through all annotations (individual objects)
foreach ($annotations as $annotation) {
    // Gets: AuthValidator, LoggingInterceptor, Route
}
```

### Filtered Results

When filtering annotations, the result maintains the same clean structure:

```php
// Filter by specific annotation class
$interceptors = $reader->getMethodAnnotations(MyClass::class, 'myMethod', InterceptWith::class);

// Or filter with custom logic
$activeAnnotations = $annotations->filter(fn($annotation) => $annotation->isActive());

// All results are AnnotationCollection with consistent access
$first = $interceptors[0];              // First annotation
$count = count($interceptors);          // Total count
foreach ($interceptors as $annotation) {
    // Iterate individual annotations
}

// Chain operations
$result = $annotations
    ->filter(fn($a) => $a->isActive())
    ->all(InterceptWith::class);
```

### Array Conversion Methods

The `AnnotationCollection` provides three different methods to convert the collection to standard PHP arrays, each serving different use cases:

#### toArray() - Mixed Key Format

The `toArray()` method creates an array with hybrid indexing that provides both class-name access for the first occurrence of each annotation type and numeric indexing for duplicates:

```php
/**
 * @Route("/api/users")
 * @InterceptWith("AuthValidator")
 * @InterceptWith("LoggingInterceptor")
 * @Cache(ttl=3600)
 */
public function getUsers() { /* ... */ }

$annotations = $reader->getMethodAnnotations(MyClass::class, 'getUsers');
$array = $annotations->toArray();

// Result structure:
// [
//     'App\Annotations\Route' => Route("/api/users"),
//     'App\Annotations\InterceptWith' => InterceptWith("AuthValidator"),
//     0 => InterceptWith("LoggingInterceptor"),  // Second InterceptWith uses numeric key
//     'App\Annotations\Cache' => Cache(ttl=3600)
// ]

// Access patterns:
$route = $array[Route::class];                    // First (and only) Route
$firstInterceptor = $array[InterceptWith::class]; // First InterceptWith
$secondInterceptor = $array[0];                   // Second InterceptWith
$cache = $array[Cache::class];                    // Cache annotation
```

This format is ideal when you need both convenient class-name access and want to preserve all duplicate annotations in a single array structure.

#### toIndexedArray() - Linear Format

The `toIndexedArray()` method returns a simple indexed array containing all annotations in their original order:

```php
$annotations = $reader->getMethodAnnotations(MyClass::class, 'getUsers');
$array = $annotations->toIndexedArray();

// Result structure:
// [
//     0 => Route("/api/users"),
//     1 => InterceptWith("AuthValidator"),
//     2 => InterceptWith("LoggingInterceptor"),
//     3 => Cache(ttl=3600)
// ]

// Access patterns:
$firstAnnotation = $array[0];   // Route
$secondAnnotation = $array[1];  // First InterceptWith
$thirdAnnotation = $array[2];   // Second InterceptWith

// Iterate through all annotations
foreach ($array as $index => $annotation) {
    echo "Annotation {$index}: " . get_class($annotation) . "\n";
}
```

This format is perfect for sequential processing, serialization, or when you need a simple list without any special key handling.

#### toGroupedArray() - Grouped by Class

The `toGroupedArray()` method organizes annotations by their class names, with each class name mapping to an array of all annotations of that type:

```php
$annotations = $reader->getMethodAnnotations(MyClass::class, 'getUsers');
$array = $annotations->toGroupedArray();

// Result structure:
// [
//     'App\Annotations\Route' => [
//         0 => Route("/api/users")
//     ],
//     'App\Annotations\InterceptWith' => [
//         0 => InterceptWith("AuthValidator"),
//         1 => InterceptWith("LoggingInterceptor")
//     ],
//     'App\Annotations\Cache' => [
//         0 => Cache(ttl=3600)
//     ]
// ]

// Access patterns:
$routes = $array[Route::class];           // Array of Route annotations
$interceptors = $array[InterceptWith::class]; // Array of InterceptWith annotations

// Process all interceptors
foreach ($array[InterceptWith::class] as $interceptor) {
    // Handle each interceptor
}

// Check if specific annotation type exists
if (isset($array[Cache::class])) {
    $cacheAnnotations = $array[Cache::class];
}

// Get count of specific annotation type
$interceptorCount = count($array[InterceptWith::class] ?? []);
```

This format is excellent for processing annotations by type, configuration systems that need to handle multiple instances of the same annotation, or when building annotation-driven frameworks.

### Choosing the Right Conversion Method

- **Use `toArray()`** when you need convenient access to single annotations by class name but also want to preserve duplicates in the same structure
- **Use `toIndexedArray()`** for simple sequential processing, serialization, or when working with external APIs that expect indexed arrays
- **Use `toGroupedArray()`** when building systems that process annotations by type, handling multiple instances of the same annotation class, or creating configuration arrays

## Annotation Format

Annotations are defined in PHP docblocks using the `@` symbol followed by the annotation name and optional parameters. The annotation reader supports various parameter formats including strings, numbers, booleans, arrays, and the `::class` magic constant.

### Basic Annotations

Simple annotations with string, numeric, and boolean parameters:

```php
/**
 * @Table(name="products")
 * @Entity
 * @Cache(ttl=3600, enabled=true)
 */
class Product {
    /**
     * @Column(type="integer", primary=true, autoincrement=true)
     */
    private $id;
    
    /**
     * @Column(type="string", length=255)
     * @Validate("required")
     * @Validate("maxLength", 255)
     */
    private $name;
}
```

### Using Class Constants

Annotations with `::class` magic constants for type-safe class references:

```php
use App\Models\User;
use App\Services\ValidationService;
use App\Events\UserCreated;

/**
 * @Entity(repository=UserRepository::class)
 * @EventListener(event=UserCreated::class)
 */
class UserService {
    /**
     * @Inject(service=ValidationService::class)
     * @Cache(driver=RedisDriver::class)
     */
    private $validator;
    
    /**
     * @Transform(transformer=UserTransformer::class)
     * @Authorize(policy=UserPolicy::class)
     */
    public function getUser(int $id): User {
        // Method implementation
    }
}
```

### Supported Parameter Types

The annotation reader supports these parameter formats:

- **Strings**: `"value"` or `'value'`
- **Numbers**: `42`, `3.14`
- **Booleans**: `true`, `false`
- **Arrays**: `{"item1", "item2"}` or `{key="value"}`
- **Class constants**: Only `::class` magic constant is supported
- **Magic class constant**: `SomeClass::class`
- **Fully qualified names**: `\App\Models\User::class`
- **Imported classes**: `User::class` (when `use App\Models\User;` is present)

### Mixed Parameter Types

You can combine different parameter types within the same annotation:

```php
/**
 * @ComplexAnnotation(
 *     type=User::class,
 *     name="user_service",
 *     priority=10,
 *     enabled=true,
 *     tags={"user", "service"}
 * )
 */
class UserService {
    // Class implementation
}
```

## Configuration

The AnnotationReader requires a Configuration object that specifies:

- Whether to use annotation caching
- The path to store annotation cache files
- The metadata source (`docblocks` by default, or `attributes`)

Select native attributes for class, method, and property reads:

```php
$config = new Configuration();
$config->setSource(Configuration::SOURCE_ATTRIBUTES);
$reader = new AnnotationReader($config);

#[\Quellabs\Canvas\Annotations\RoutePrefix('api')]
class ApiController {
    #[\Quellabs\Canvas\Annotations\Route('/users', methods: ['GET'])]
    public function users(): void {}
}
```

The equivalent docblock metadata is `@RoutePrefix("api")` on the class and `@Route("/users", methods={"GET"})` on the method. Each reader uses one source for reflected metadata. `getAnnotations($docblock)` always parses the supplied string as docblock text. Disk caches use separate filenames for each source.

For application-defined attributes, implement `AnnotationInterface`, add a PHP `#[\Attribute(...)]` declaration with the correct targets, and use constructor parameter names that match the keys returned by `getParameters()`. `Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters` provides a dense `getParameters()` when each constructor parameter has a same-named declared property. A constructor with exactly one plain `array` parameter is reserved for legacy annotations; use a nullable array parameter if that is the sole natural argument. Legacy annotations continue to work in docblock mode. Canvas `CacheContext`, `InterceptWith`, and `WithContext` also support native attributes with arbitrary named options; their variadic constructors preserve the flat parameter arrays used by cache, AOP, and dependency injection.

```php
#[\Quellabs\Canvas\Annotations\CacheContext(namespace: 'users', driver: 'redis')]
#[\Quellabs\Canvas\Annotations\InterceptWith(\App\Aspects\AuditAspect::class, priority: 10, channel: 'admin')]
#[\Quellabs\Canvas\Annotations\WithContext('engine', context: 'blade')]
public function show(): void {}
```

Changing a repository annotation class to a named constructor changes direct construction from `new Route(['value' => '/users'])` to `new Route(value: '/users')`. Canvas accepts `annotation_source` in its configuration; ObjectQuel accepts `Configuration::setAnnotationSource()` and the same `annotation_source` config key through Canvas-ObjectQuel or Sculpt.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
