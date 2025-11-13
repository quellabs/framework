# Quellabs Dependency Injection

A lightweight, PSR-compliant dependency injection container for PHP with advanced autowiring capabilities and a unique contextual container pattern that allows interface-first service resolution without requiring knowledge of specific service IDs.

## Features

- **Autowiring**: Automatically resolve dependencies through reflection
- **Service Providers**: Customize how specific services are instantiated
- **Direct Instantiation**: Use `make()` for simple dependency injection without service providers
- **Contextual Resolution**: Use the `for()` method to specify which implementation to use when multiple providers support the same interface
- **Service Discovery**: Automatically discover service providers from Composer configurations
- **Circular Dependency Detection**: Prevents infinite loops in dependency graphs
- **Method Injection**: Support for dependency injection in any method, not just constructors
- **Default Service Fallback**: Automatically handle classes with no dedicated provider
- **Singleton by Default**: The default service provider resolves all classes as singletons
- **All Parameters Magic**: Special `$__all__` parameter for accessing all injection parameters

## Installation

```bash
composer require quellabs/dependency-injection
```

## Basic Usage

```php
// Create a container
$container = new \Quellabs\DependencyInjection\Container();

// Get a service (automatically resolves all dependencies)
$service = $container->get(MyService::class);

// Create a new instance without service providers
$instance = $container->make(MyService::class);

// Call a method with autowired dependencies
$result = $container->invoke($service, 'doSomething', ['extraParam' => 'value']);
```

## Service Resolution Methods

The container provides two primary methods for resolving dependencies:

### `get()` - Service Provider Resolution

The `get()` method uses the full service provider pattern and is the recommended approach for most use cases:

```php
// Uses service providers (singleton by default)
$service = $container->get(MyService::class);
$sameService = $container->get(MyService::class); // Returns the same instance

// Works with contextual resolution
$objectQuelEM = $container->for('objectquel')->get(EntityManagerInterface::class);
```

**When to use `get()`:**
- When you want to leverage service providers for custom instantiation logic
- When you need singleton behavior (default)
- When working with interfaces that have multiple implementations
- For most production use cases where you want consistent service management

### `make()` - Direct Instantiation

The `make()` method bypasses service providers and creates instances directly using reflection:

```php
// Always creates a new instance, bypassing service providers
$instance1 = $container->make(MyService::class);
$instance2 = $container->make(MyService::class); // Creates a different instance

// Still supports parameter injection
$instance = $container->make(MyService::class, ['customParam' => 'value']);
```

**When to use `make()`:**
- When you need a fresh instance every time (transient behavior)
- For testing scenarios where you want to avoid singleton caching
- When you want simple dependency injection without custom provider logic
- For temporary objects or request-specific instances
- When prototyping or when service provider configuration is overkill

### Key Differences

| Feature | `get()` | `make()` |
|---------|---------|----------|
| **Service Providers** | Uses registered providers | Bypasses providers |
| **Singleton Behavior** | Depends on service provider (DefaultServiceProvider uses singleton) | Always creates new instances |
| **Contextual Resolution** | Supports `for()` contexts | No context support |
| **Custom Instantiation** | Provider-defined logic | Direct reflection only |
| **Interface Resolution** | Via providers | Cannot resolve interfaces |
| **Performance** | Optimized (caching) | Slightly faster per call |

### Practical Examples

```php
// Service provider pattern - recommended for services
$logger = $container->get(LoggerInterface::class);        // Uses LoggerServiceProvider
$cache = $container->get(CacheInterface::class);          // Uses CacheServiceProvider

// Direct instantiation - good for temporary objects
$request = $container->make(HttpRequest::class);          // New instance every time
$validator = $container->make(FormValidator::class);      // Fresh validator

// Mixed usage example
class OrderService {
    public function __construct(
        private LoggerInterface $logger,     // Injected via get() (singleton)
        private EmailService $emailService  // Injected via get() (singleton)
    ) {}
    
    public function processOrder(array $orderData): void {
        // Create a fresh order processor for each order
        $processor = $this->container->make(OrderProcessor::class, [
            'orderData' => $orderData,
            'timestamp' => time()
        ]);
        
        $processor->process();
    }
}
```

## Contextual Service Resolution

The container supports contextual service resolution through the `for()` method, allowing you to specify which implementation to use when multiple service providers support the same interface.

```php
// Get a specific implementation using context
$objectQuelEM = $container->for('objectquel')->get(EntityManagerInterface::class);
$doctrineEM = $container->for('doctrine')->get(EntityManagerInterface::class);

// Create a contextual container with 'objectquel' context
$objectQuelContainer = $container->for('objectquel');

// Now get multiple services, all using the 'objectquel' context
$em = $objectQuelContainer->get(EntityManagerInterface::class);           // ObjectQuel EntityManager
$queryBuilder = $objectQuelContainer->get(QueryBuilderInterface::class);  // ObjectQuel QueryBuilder

// Use complex context with multiple parameters
$cache = $container->for(['driver' => 'redis', 'cluster' => 'main'])->get(CacheInterface::class);

// Default behavior (no context)
$logger = $container->get(LoggerInterface::class); // Uses default provider
```

## Service Providers

Service providers allow you to customize how services are created. A service provider can:

- Define specific instantiation logic for a service
- Support instantiation of interfaces
- Use contextual information to determine if they should handle a specific request

### Default Service Provider

By default, all classes without a dedicated service provider are handled by the `DefaultServiceProvider`, which implements a singleton pattern. This means that for any given class, only one instance will ever be created and shared across the application.

### Creating a Service Provider

```php
/**
 * Service Provider class for dependency injection
 * Extends the base ServiceProvider from Quellabs eco system
 */
use Quellabs\DependencyInjection\Provider\ServiceProvider;

/**
 * Custom service provider that handles instantiation of specific services
 */
class MyServiceProvider extends ServiceProvider {

    /**
     * Determines if this provider can create the requested class
     * @param string $className The fully qualified class name to check
     * @param array $context Context information for provider selection (optional)
     * @return bool True if this provider supports creating the class
     */
    public function supports(string $className, array $context = []): bool {
        // Support either the exact MyService class or any class implementing MyInterface
        $supportsClass = $className === MyService::class || is_subclass_of($className, MyInterface::class);
        
        // Check context if provider name is specified
        if (isset($context['provider'])) {
            return $supportsClass && $context['provider'] === 'myservice';
        }
        
        return $supportsClass;
    }
    
    /**
     * Creates an instance of the requested class with dependencies injected
     * @param string $className The fully qualified class name to instantiate
     * @param array $dependencies Array of dependencies to inject into the constructor
     * @return object The instantiated object
     */
    public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext=null): object
        // Instantiate the class by passing all dependencies to the constructor
        $instance = new $className(...$dependencies);
        
        // Apply post-instantiation configuration for specific service types
        if ($instance instanceof MyService) {
            // Call an initialization method if the instance is MyService
            $instance->initialize();
        }
        
        // Return the fully configured instance
        return $instance;
    }
}
```

### Registering a Service Provider

```php
$container->register(new MyServiceProvider());
```

### Multiple Implementations with Context

When you have multiple service providers that support the same interface, you can use contextual resolution to specify which implementation to use:

```php
// ObjectQuel Entity Manager Provider
class ObjectQuelServiceProvider extends ServiceProvider {
    public function supports(string $className, array $context = []): bool {
        return $className === EntityManagerInterface::class 
            && ($context['provider'] ?? null) === 'objectquel';
    }
    
    public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext=null): object
        return new ObjectQuelEntityManager($this->createConfiguration());
    }
}

// Doctrine Entity Manager Provider  
class DoctrineServiceProvider extends ServiceProvider {
    public function supports(string $className, array $context = []): bool {
        return $className === EntityManagerInterface::class 
            && ($context['provider'] ?? null) === 'doctrine';
    }
    
    public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext=null): object
        return new DoctrineEntityManager($this->createConfiguration());
    }
}

// Usage
$objectQuelEM = $container->for('objectquel')->get(EntityManagerInterface::class);
$doctrineEM = $container->for('doctrine')->get(EntityManagerInterface::class);
```

## Automatic Service Discovery

The container can automatically discover and register service providers through multiple methods. The Dependency Injection package integrates the Quellabs Discover functionality, giving you powerful service discovery capabilities right out of the box.

### Basic Discovery with Composer Configuration

#### Project-Level Configuration

In your `composer.json`:

```json
{
  "extra": {
    "discover": {
      "di": {
        "providers": [
          "App\\Providers\\MyServiceProvider",
          "App\\Providers\\DatabaseServiceProvider"
        ]
      }
    }
  }
}
```

For registering just one service provider:

```json
{
  "extra": {
    "discover": {
      "di": {
        "provider": "MyPackage\\MyPackageServiceProvider"
      }
    }
  }
}
```

Note the difference between the plural "providers" key (for an array of providers) and the singular "provider" key (for a single provider class).

For more information about Quellabs Discover and its advanced features, visit [https://github.com/quellabs/discover](https://github.com/quellabs/discover).

## Singleton and Transient Patterns

Since the default provider already implements the singleton pattern, you may want to create a custom provider for transient (non-singleton) services:

```php
use Quellabs\DependencyInjection\Provider\ServiceProvider;

/**
 * TransientServiceProvider specializes in providing non-singleton instances.
 * When a class is supported by this provider, a new instance will be created
 * for each request/resolution rather than being cached and reused.
 */
class TransientServiceProvider extends ServiceProvider {
    
    /**
     * Determines if this provider should handle the requested class.
     * @param string $className The fully qualified class name to check
     * @param array $context Context information for provider selection (optional)
     * @return bool True if this provider should create the instance
     */
    public function supports(string $className, array $context = []): bool {
        // Define which classes should be created as new instances each time
        // These are typically stateful classes that shouldn't be shared between requests
        return in_array($className, [
            RequestContext::class,    // Contains request-specific data
            TemporaryData::class      // Holds temporary state that shouldn't persist
        ]);
    }
    
    /**
     * Creates a new instance of the requested class.
     * @param string $className The class to instantiate
     * @param array $dependencies Array of constructor dependencies already resolved
     * @return object A new instance of the requested class
     */
    public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext=null): object
        // Always create a new instance without caching
        // The spread operator (...) unpacks the dependencies array as arguments
        return new $className(...$dependencies);
    }
}
```

Alternatively, you can use the `make()` method for simple transient behavior without creating a custom service provider:

```php
// These will be different instances
$processor1 = $container->make(OrderProcessor::class);
$processor2 = $container->make(OrderProcessor::class);

// Versus singleton behavior with get()
$service1 = $container->get(OrderService::class);
$service2 = $container->get(OrderService::class); // Same instance as service1
```

## The `$__all__` Magic Parameter

The dependency injection container supports a special magic parameter named `$__all__` that provides access to all parameters passed to the container during method resolution. This is particularly useful for services that need flexible configuration or want to access additional context data.

### How It Works

When the container encounters a parameter named `$__all__` in a constructor or method signature, it automatically injects the complete parameters array that was passed to the container, giving you access to all available data, including parameters that don't have corresponding method arguments.

### Basic Example

```php
class ConfigurableService {
    public function __construct(
        private DatabaseConnection $db,
        private LoggerInterface $logger,
        private array $__all__ = []
    ) {
        // $db and $logger are resolved normally
        // $__all__ contains all parameters passed to the container
    }
}

// Usage
$service = $container->get(ConfigurableService::class, [
    'database_host' => 'localhost',
    'log_level' => 'debug',
    'api_key' => 'secret123'
]);

// Inside ConfigurableService constructor:
// $__all__ = [
//     'database_host' => 'localhost',
//     'log_level' => 'debug', 
//     'api_key' => 'secret123'
// ]
```

### Important Notes

- The `$__all__` parameter receives the original parameters array passed to the container - no resolution or transformation is applied to these values
- Other constructor/method parameters are resolved normally through the dependency injection process
- If no parameters are passed to the container, `$__all__` will be an empty array
- The `$__all__` parameter should typically have a default value of `[]` to handle cases where no additional parameters are provided
- This feature works with both `get()` and `make()` methods, as well as `invoke()` for method injection

## Advanced Configuration

### Debug Mode

Enable debug mode to see detailed error information:

```php
$container = new \Quellabs\DependencyInjection\Container(null, true);
```

### Custom Base Path

Specify a custom base path for service discovery:

```php
$container = new \Quellabs\DependencyInjection\Container('/path/to/app');
```

### Custom Configuration Key

Use a custom key for service discovery in composer.json:

```php
$container = new \Quellabs\DependencyInjection\Container(null, false, 'custom-key');
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License