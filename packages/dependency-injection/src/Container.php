<?php
	
	namespace Quellabs\DependencyInjection;
	
	use Quellabs\DependencyInjection\Autowiring\MethodContext;
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\DependencyInjection\Autowiring\Autowirer;
	use Quellabs\Contracts\DependencyInjection\ServiceProvider;
	use Quellabs\DependencyInjection\Provider\DefaultServiceProvider;
	
	/**
	 * Container with centralized autowiring for all services
	 */
	class Container implements \Quellabs\Contracts\DependencyInjection\Container {
		
		/**
		 * Registered service providers
		 * @var ServiceProvider[]
		 */
		protected array $providers = [];
		
		/**
		 * The autowirer instance
		 */
		protected Autowirer $autowire;
		
		/**
		 * Dependency resolution stack to detect circular dependencies
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
		private Discover $discovery;
		
		/**
		 * @var array|string[]
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
			$this->autowire = new Autowirer($this);
			
			// Automatically discover and register service providers
			$this->registerProviders();
		}
		
		/**
		 * Registers a service provider with the container.
		 * @param ServiceProvider $provider The service provider instance to register
		 * @return self Returns the current instance for method chaining
		 */
		public function register(ServiceProvider $provider): self {
			// Store the provider in the providers array using its hash as the key
			$this->providers[spl_object_hash($provider)] = $provider;
			
			// Return the current instance to allow method chaining
			return $this;
		}
		
		/**
		 * Unregisters a service provider from the container.
		 * @param ServiceProvider $provider The service provider instance to unregister
		 * @return self Returns the current instance for method chaining
		 */
		public function unregister(ServiceProvider $provider): self {
			// Remove the provider from the providers array using its hash as the key
			unset($this->providers[spl_object_hash($provider)]);
			
			// Return the current instance to allow method chaining
			return $this;
		}
		
		/**
		 * Set context for subsequent get() calls
		 * @param string|array $context Context to apply - string is converted to ['provider' => $context]
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
		 * @param string $className The fully qualified class name to find a provider for
		 * @return ServiceProvider The provider that supports the class or the default provider
		 */
		public function findProvider(string $className): ServiceProvider {
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
				$className === \Quellabs\Contracts\DependencyInjection\Container::class ||
				is_a($this, $className)
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
		 * Get a service with centralized dependency resolution
		 * @param string $className Class name to resolve
		 * @param array $parameters Additional parameters for creation
		 * @param MethodContext|null $methodContext
		 * @return object|null
		 */
		public function get(string $className, array $parameters = [], ?MethodContext $methodContext=null): ?object {
			return $this->resolveWithDependencies($className, $parameters, true, $methodContext);
		}
		
		/**
		 * Create an instance with autowired constructor parameters
		 * Bypasses service providers - only handles dependency injection
		 * @param string $className
		 * @param array $parameters Additional/override parameters
		 * @return object|null
		 */
		public function make(string $className, array $parameters = []): ?object {
			return $this->resolveWithDependencies($className, $parameters, false);
		}
		
		/**
		 * Invoke a method with autowired arguments
		 * @param object $instance
		 * @param string $methodName
		 * @param array $parameters
		 * @return mixed
		 */
		public function invoke(object $instance, string $methodName, array $parameters = []): mixed {
			// Get method arguments with all dependencies resolved
			$args = $this->autowire->getMethodArguments($instance, $methodName, $parameters);
			
			// Call the method with the resolved arguments
			return $instance->$methodName(...$args);
		}
		
		/**
		 * Handles proper cloning of the container to ensure contextual isolation.
		 * @return void
		 */
		public function __clone(): void {
			// Clone and update autowirer's container reference
			$this->autowire = new Autowirer($this);
			
			// Reset context for the cloned instance
			$this->context = [];
			
			// Resolution stack must be independent
			$this->resolutionStack = [];
		}
		
		/**
		 * Resolves a class instance with its dependencies, handling circular dependency detection
		 * and supporting both service provider and direct instantiation methods.
		 * @param string $className The fully qualified class name to resolve
		 * @param array $parameters Manual parameters to override autowired dependencies
		 * @param bool $useServiceProvider Whether to use service provider for instantiation
		 * @return object|null The resolved instance or null if resolution fails
		 * @throws \RuntimeException When circular dependencies are detected
		 */
		protected function resolveWithDependencies(
			string $className,
			array $parameters,
			bool $useServiceProvider,
			MethodContext $methodContext = null
		): ?object {
			// Special case: Return container instance when requesting the container itself
			// This allows for self-injection of the container into other services
			if (
				$className === self::class ||
				$className === \Quellabs\Contracts\DependencyInjection\Container::class ||
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
		 * @param string $className The fully qualified class name to resolve
		 * @param array $parameters Manual parameters to override autowired dependencies
		 * @param bool $useServiceProvider Whether to use service provider for instantiation
		 * @param MethodContext|null $methodContext Optional method context for advanced scenarios
		 * @return object The created instance
		 * @throws \RuntimeException|\ReflectionException When instantiation fails
		 */
		protected function createInstance(
			string $className,
			array $parameters,
			bool $useServiceProvider,
			MethodContext $methodContext = null
		): object {
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
		 * @param string $className The class being resolved
		 * @param callable $resolutionCallback The callback that performs the actual resolution
		 * @return object The resolved instance
		 * @throws \RuntimeException When circular dependencies are detected or resolution fails
		 */
		protected function safeResolve(string $className, callable $resolutionCallback): object {
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
				
				// Execute the resolution callback
				$instance = $resolutionCallback();
				
				// Clean up: Remove current class from resolution stack since we're done
				// This allows the same class to be resolved again in different dependency chains
				array_pop($this->resolutionStack);
				
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
				if ($provider instanceof ServiceProvider) {
					$this->register($provider);
				}
			}
			
			return $this;
		}
	}