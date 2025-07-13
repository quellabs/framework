<?php
	
	namespace Quellabs\DependencyInjection;
	
	use PhpParser\Builder\Method;
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
		 * Base path where the application is installed
		 */
		protected string $basePath;
		
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
		 * @param string|null $basePath Base path of the application
		 * @param string $familyName The key to look for in composer.json (default: 'di')
		 */
		public function __construct(?string $basePath = null, string $familyName = 'di') {
			$this->autowire = new Autowirer($this);
			$this->basePath = $basePath ?? getcwd();
			
			// Create the default provider
			$this->defaultProvider = new DefaultServiceProvider();
			
			// Create the service discoverer
			$this->discovery = new Discover();
			$this->discovery->addScanner(new ComposerScanner($familyName));
			$this->discovery->discover();
			
			// Automatically discover and register service providers
			$this->registerProviders();
		}
		
		/**
		 * Registers a service provider with the container.
		 * @param ServiceProvider $provider The service provider instance to register
		 * @return self Returns the current instance for method chaining
		 */
		public function register(ServiceProvider $provider): self {
			// Store the provider in the providers array using its class name as the key
			$this->providers[get_class($provider)] = $provider;
			
			// Return the current instance to allow method chaining
			return $this;
		}
		
		/**
		 * Unregisters a service provider from the container.
		 * @param ServiceProvider $provider The service provider instance to unregister
		 * @return self Returns the current instance for method chaining
		 */
		public function unregister(ServiceProvider $provider): self {
			// Remove the provider from the providers array using its class name as the key
			unset($this->providers[get_class($provider)]);
			
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
			try {
				// Special case: Return container instance when requesting the container itself
				// This allows for self-injection of the container into other services
				if (
					$className === self::class ||
					$className === \Quellabs\Contracts\DependencyInjection\Container::class ||
					is_a($this, $className)
				) {
					return $this;
				}
				
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
				
				// Autowire constructor dependencies by analyzing the class constructor
				// Merges manual parameters with automatically resolved dependencies
				$dependencies = $this->autowire->getMethodArguments($className, '__construct', $parameters);
				
				// Choose instantiation method based on configuration
				if ($useServiceProvider) {
					// Use service provider pattern for more complex instantiation logic
					// Service providers can handle custom initialization, configuration, etc.
					$provider = $this->findProvider($className);
					
					// Use the provider to create an instance
					$instance = $provider->createInstance($className, $dependencies, $this->context, $methodContext);
				} else {
					// Direct reflection-based instantiation for simple cases
					// Creates instance directly using PHP's reflection API
					$reflection = new \ReflectionClass($className);
					$instance = $reflection->newInstanceArgs($dependencies);
				}
				
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
				
				// Log the error for debugging
				error_log("Failed to resolve dependency '{$className}': " . $e->getMessage());
				
				// Wrap and rethrow with additional context
			    throw new \RuntimeException(
				    "Failed to resolve dependency '{$className}': " . $e->getMessage(),
				    0,
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