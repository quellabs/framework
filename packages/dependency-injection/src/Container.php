<?php
	
	namespace Quellabs\DependencyInjection;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\DependencyInjection\Autowiring\MethodContext;
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\DependencyInjection\Autowiring\Autowirer;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;
	use Quellabs\DependencyInjection\Provider\DefaultServiceProvider;
	
	/**
	 * Container with centralized autowiring for all services
	 */
	class Container implements ContainerInterface {
		
		/**
		 * Registered service providers
		 * @var ServiceProviderInterface[]
		 */
		protected array $providers = [];
		
		/**
		 * The autowirer instance
		 */
		protected Autowirer $autowire;
		
		/**
		 * Dependency resolution stack to detect circular dependencies
		 * @var list<string>
		 */
		protected array $resolutionStack = [];
		
		/**
		 * Default service provider for classes with no dedicated provider
		 */
		protected DefaultServiceProvider $defaultProvider;
		
		/**
		 * Service discovery
		 * @var Discover
		 */
		protected Discover $discovery;
		
		/**
		 * @var array<string, mixed>
		 */
		private array $context = [];
		
		/**
		 * Container constructor with automatic service discovery
		 * @param string $familyName The key to look for in composer.json (default: 'di')
		 */
		public function __construct(string $familyName = 'di') {
			// Create the service discoverer
			$this->discovery = new Discover();
			$this->discovery->addScanner(new ComposerScanner($familyName));
			$this->discovery->discover();
			
			// Create the default provider
			$this->defaultProvider = new DefaultServiceProvider($this->discovery);
			
			// Create autowirer AFTER default provider
			$this->autowire = $this->createAutowirer();
			
			// Automatically discover and register service providers
			$this->registerProviders();
		}
		
		/**
		 * Returns the discover class
		 * @return Discover
		 */
		public function getDiscovery(): Discover {
			return $this->discovery;
		}
		
		/**
		 * Registers a service provider with the container.
		 * @param ServiceProviderInterface $provider The service provider instance to register
		 * @return self Returns the current instance for method chaining
		 */
		public function register(ServiceProviderInterface $provider): self {
			// Store the provider in the providers array using its hash as the key
			$this->providers[spl_object_hash($provider)] = $provider;
			
			// Return the current instance to allow method chaining
			return $this;
		}
		
		/**
		 * Unregisters a service provider from the container.
		 * @param ServiceProviderInterface $provider The service provider instance to unregister
		 * @return self Returns the current instance for method chaining
		 */
		public function unregister(ServiceProviderInterface $provider): self {
			// Remove the provider from the providers array using its hash as the key
			unset($this->providers[spl_object_hash($provider)]);
			
			// Return the current instance to allow method chaining
			return $this;
		}
		
		/**
		 * Set context for subsequent get() calls
		 * @param string|array<string, mixed> $context Context to apply - string is converted to ['provider' => $context]
		 * @return self Returns a cloned instance with the specified context applied
		 */
		public function for(string|array $context): self {
			// Create a clone to avoid modifying the original container instance
			$clone = clone $this;
			
			// Handle string context by converting it to a provider-specific array format
			// Otherwise, use the provided array context directly
			if (is_string($context)) {
				$clone->context = ['provider' => $context];
			} else {
				$clone->context = $context;
			}
			
			// Return the cloned instance with the new context
			return $clone;
		}
		
		/**
		 **
		 * Find the appropriate service provider for a given class name.
		 * @param class-string $className The fully qualified class name to find a provider for
		 * @return ServiceProviderInterface The provider that supports the class or the default provider
		 */
		public function findProvider(string $className): ServiceProviderInterface {
			// Iterate through all registered providers to find one that supports the class
			foreach ($this->providers as $provider) {
				// Check if this provider supports the class name with the current context
				if ($provider->supports($className, $this->context)) {
					return $provider;
				}
			}
			
			// No specific provider found, return the default provider as fallback
			return $this->defaultProvider;
		}
		
		/**
		 * Determine if the container can resolve a given service.
		 * @param string $className Service identifier (class name or interface)
		 * @return bool True if the service can be resolved, false if not
		 */
		public function has(string $className): bool {
			// Check if it's the container itself
			if (
				$className === self::class ||
				$className === ContainerInterface::class
			) {
				return true;
			}
			
			// Check if it's a concrete class that exists
			if (class_exists($className)) {
				return true;
			}
			
			// Check if any provider supports this interface
			if (interface_exists($className)) {
				foreach ($this->providers as $provider) {
					if ($provider->supports($className, $this->context)) {
						return true;
					}
				}
				
				// Check if default provider supports it
				return $this->defaultProvider->supports($className, $this->context);
			}
			
			return false;
		}
		
		/**
		 * Get a service with centralized dependency resolution.
		 * Returns null when the provider signals the resource does not exist
		 * (e.g. no database row for a requested entity).
		 * @template T of object
		 * @param class-string<T> $className Class name to resolve
		 * @param array<string, mixed> $parameters Additional parameters for creation
		 * @param MethodContextInterface|null $methodContext
		 * @return T|null
		 * @throws \RuntimeException When circular dependencies are detected or resolution fails
		 */
		public function get(string $className, array $parameters = [], ?MethodContextInterface $methodContext = null): ?object {
			// Resolve the class; null means the provider found no result
			$instance = $this->resolveWithDependencies($className, $parameters, true, $methodContext);
			
			if ($instance === null) {
				return null;
			}
			
			// If the instance is not of the requested type, the provider has a bug
			if (!$instance instanceof $className) {
				throw new \RuntimeException("Container returned an instance of " . get_class($instance) . ", expected {$className}");
			}
			
			/**
			 * PHPStan needs to be told instance is of type T
			 * @var T $instance
			 */
			return $instance;
		}
		
		/**
		 * Create an instance with autowired constructor parameters.
		 * Bypasses service providers - only handles dependency injection.
		 * Direct instantiation can never produce null, so this method retains a non-nullable return.
		 * @template T of object
		 * @param class-string<T> $className Class name to resolve
		 * @param array<string, mixed> $parameters Additional/override parameters
		 * @return T
		 * @throws \RuntimeException When circular dependencies are detected or creation fails
		 */
		public function make(string $className, array $parameters = []): object {
			// Resolve the class; null is not possible here since make() bypasses providers
			$instance = $this->resolveWithDependencies($className, $parameters, false);
			
			// resolveWithDependencies only returns null when a provider does so;
			// make() never uses providers, so null here would be a container bug
			if ($instance === null) {
				throw new \RuntimeException("Unexpected null returned during make() for '{$className}'");
			}
			
			// If the instance is not of the requested type, pass an error
			if (!$instance instanceof $className) {
				throw new \RuntimeException("Container returned an instance of " . get_class($instance) . ", expected {$className}");
			}

			/**
			 * PHPStan needs to be told instance is of type T
			 * @var T $instance
			 */
			return $instance;
		}
		
		/**
		 * Invoke a method with autowired arguments
		 * @param object $instance
		 * @param string $methodName
		 * @param MethodContextInterface|null $methodContext
		 * @param array<string, mixed> $parameters
		 * @return mixed
		 */
		public function invoke(
			object $instance,
			string $methodName,
			array $parameters = [],
			?MethodContextInterface $methodContext = null
		): mixed {
			// Get method arguments with all dependencies resolved
			$args = $this->autowire->getMethodArguments($instance, $methodName, $parameters, $methodContext);
			
			// Call the method with the resolved arguments
			return $instance->$methodName(...$args);
		}
		
		/**
		 * Instantiate a class with autowired constructor dependencies without
		 * pushing it onto the resolution stack. Used exclusively for provider
		 * instantiation during boot, where tracking the provider class itself
		 * on the stack would cause false circular dependency detection.
		 * @template T of object
		 * @param class-string<T> $className
		 * @return T
		 * @throws \ReflectionException
		 */
		public function instantiate(string $className): object {
			$arguments = $this->autowire->getMethodArguments($className, '__construct');
			$reflection = new \ReflectionClass($className);
			return $reflection->newInstanceArgs($arguments);
		}
		
		/**
		 * Handles proper cloning of the container to ensure contextual isolation.
		 * @return void
		 */
		public function __clone(): void {
			// Clone and update autowirer's container reference
			$this->autowire = $this->createAutowirer();
			
			// Reset context for the cloned instance
			$this->context = [];
			
			// Resolution stack must be independent
			$this->resolutionStack = [];
		}
		
		/**
		 * Returns a new autowirer
		 * @return Autowirer
		 */
		protected function createAutowirer(): Autowirer {
			return new Autowirer($this);
		}
		
		/**
		 * Resolves a class instance with its dependencies, handling circular dependency detection
		 * and supporting both service provider and direct instantiation methods.
		 * Returns null when the provider signals that the resource does not exist.
		 * @param class-string $className The fully qualified class name to resolve
		 * @param array<string, mixed> $parameters Manual parameters to override autowired dependencies
		 * @param bool $useServiceProvider Whether to use service provider for instantiation
		 * @param MethodContextInterface|null $methodContext Optional method context for advanced scenarios
		 * @return object|null
		 * @throws \RuntimeException When circular dependencies are detected
		 */
		protected function resolveWithDependencies(
			string $className,
			array $parameters,
			bool $useServiceProvider,
			?MethodContextInterface $methodContext = null
		): ?object {
			// Special case: Return container instance when requesting the container itself
			// This allows for self-injection of the container into other services
			if (
				$className === self::class ||
				$className === ContainerInterface::class ||
				is_a($this, $className)
			) {
				return $this;
			}
			
			// Use safe resolution wrapper to handle circular dependencies
			return $this->safeResolve($className, function() use ($className, $parameters, $useServiceProvider, $methodContext) {
				return $this->createInstance($className, $parameters, $useServiceProvider, $methodContext);
			});
		}
		
		/**
		 * Creates an instance of the specified class using either service provider or direct instantiation.
		 * Returns null when the provider signals that the resource does not exist.
		 * @param class-string $className The fully qualified class name to resolve
		 * @param array<string, mixed> $parameters Manual parameters to override autowired dependencies
		 * @param bool $useServiceProvider Whether to use service provider for instantiation
		 * @param MethodContextInterface|null $methodContext Optional method context for advanced scenarios
		 * @return object|null The created instance, or null if the provider found no result
		 * @throws \ReflectionException When instantiation fails
		 */
		protected function createInstance(
			string $className,
			array $parameters,
			bool $useServiceProvider,
			?MethodContextInterface $methodContext = null
		): ?object {
			// Validate class/interface exists first
			if (!class_exists($className) && !interface_exists($className)) {
				throw new \RuntimeException("Class or interface '{$className}' does not exist");
			}
			
			// Now check if it's an interface
			$reflection = new \ReflectionClass($className);
			$isInterface = $reflection->isInterface();
			
			// Show error when user tries to make() an interface
			if ($isInterface && !$useServiceProvider) {
				throw new \RuntimeException(
					"Cannot instantiate interface '{$className}' without a service provider. " .
					"Use get() instead of make() for interface resolution."
				);
			}
			
			// For interfaces, skip autowiring and pass raw parameters to service provider
			// Interfaces have no constructors, so autowiring would fail anyway
			if ($isInterface) {
				$provider = $this->findProvider($className);
				return $provider->createInstance($className, $parameters, $this->context, $methodContext);
			}
			
			// For concrete classes, autowire constructor dependencies
			// Merges manual parameters with automatically resolved dependencies
			$arguments = $this->autowire->getMethodArguments($className, '__construct', $parameters);
			
			// Choose instantiation method based on configuration
			if ($useServiceProvider) {
				// Use service provider pattern for more complex instantiation logic
				// Service providers can handle custom initialization, configuration, etc.
				$provider = $this->findProvider($className);
				
				// Use the provider to create an instance
				return $provider->createInstance($className, $arguments, $this->context, $methodContext);
			}
			
			// Direct reflection-based instantiation for simple cases
			// Creates instance directly using PHP's reflection API
			return $reflection->newInstanceArgs($arguments);
		}
		
		/**
		 * Safely executes a resolution callback while managing the resolution stack for circular dependency detection.
		 * @template T of object
		 * @param string $className The class being resolved
		 * @param callable(): (T|null) $resolutionCallback The callback that performs the actual resolution
		 * @return T|null The resolved instance, or null if the provider found no result
		 * @throws \RuntimeException When circular dependencies are detected or resolution fails
		 */
		protected function safeResolve(string $className, callable $resolutionCallback): ?object {
			try {
				// Circular dependency protection: Check if we're already resolving this class
				// This prevents infinite recursion when Class A depends on Class B which depends on Class A
				if (in_array($className, $this->resolutionStack)) {
					throw new \RuntimeException(
						"Circular dependency detected: " .
						implode(" -> ", $this->resolutionStack) .
						" -> {$className}"
					);
				}
				
				// Track current resolution in the stack for circular dependency detection
				// This maintains a breadcrumb trail of what we're currently resolving
				$this->resolutionStack[] = $className;
				
				// Execute the resolution callback; null is a valid result
				$instance = $resolutionCallback();
				
				// Clean up: Remove current class from resolution stack since we're done
				// This allows the same class to be resolved again in different dependency chains
				array_pop($this->resolutionStack);
				
				// Return the instance
				return $instance;
				
			} catch (\Throwable $e) {
				// Error recovery: Clean up the resolution stack to prevent corruption
				// Find and remove everything up to and including the current class
				if (in_array($className, $this->resolutionStack)) {
					// Remove items from stack until we find our class (handles nested failures)
					while (end($this->resolutionStack) !== $className && !empty($this->resolutionStack)) {
						array_pop($this->resolutionStack);
					}
					
					// Remove the current class itself
					array_pop($this->resolutionStack);
				}
				
				// Wrap and rethrow with additional context
				throw new \RuntimeException(
					$e->getMessage(),
					$e->getCode(),
					$e
				);
			}
		}
		
		/**
		 * Discover and register service providers
		 * @return self
		 */
		protected function registerProviders(): self {
			// Register each discovered provider with the container
			foreach ($this->discovery->getProviders() as $provider) {
				if ($provider instanceof ServiceProviderInterface) {
					$this->register($provider);
				}
			}
			
			return $this;
		}
	}