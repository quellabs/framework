<?php
	
	namespace Quellabs\Contracts\DependencyInjection;
	
	/**
	 * Container interface for dependency injection with autowiring capabilities
	 */
	interface Container {
		
		/**
		 * Registers a service provider with the container.
		 * @param ServiceProvider $provider The service provider instance to register
		 * @return self Returns the current instance for method chaining
		 */
		public function register(ServiceProvider $provider): self;
		
		/**
		 * Unregisters a service provider from the container.
		 * @param ServiceProvider $provider The service provider instance to unregister
		 * @return self Returns the current instance for method chaining
		 */
		public function unregister(ServiceProvider $provider): self;
		
		/**
		 * Set context for subsequent get() calls.
		 * @param string|array $context Context to apply - string is converted to ['provider' => $context]
		 * @return self Returns a cloned instance with the specified context applied
		 */
		public function for(string|array $context): self;
		
		/**
		 * Find the appropriate service provider for a given class name.
		 * @param string $className The fully qualified class name to find a provider for
		 * @return ServiceProvider The provider that supports the class or the default provider
		 */
		public function findProvider(string $className): ServiceProvider;
		
		/**
		 * Get a service with centralized dependency resolution.
		 * @template T of object
		 * @param class-string<T> $className Class or interface name to resolve
		 * @param array $parameters Additional parameters for creation
		 * @return T|null The resolved service instance or null if resolution fails
		 * @throws \RuntimeException When circular dependencies are detected or resolution fails
		 */
		public function get(string $className, array $parameters = []): ?object;
		
		/**
		 * Create an instance with autowired constructor parameters.
		 * @template T of object
		 * @param class-string<T> $className The fully qualified class name to instantiate
		 * @param array $parameters Additional/override parameters for constructor
		 * @return T|null The created instance or null if creation fails
		 * @throws \RuntimeException When circular dependencies are detected or creation fails
		 */
		public function make(string $className, array $parameters = []): ?object;
		
		/**
		 * Invoke a method with autowired arguments.
		 * @param object $instance The object instance to call the method on
		 * @param string $methodName The name of the method to invoke
		 * @param array $parameters Additional/override parameters for the method
		 * @return mixed The return value of the invoked method
		 * @throws \RuntimeException When method resolution or invocation fails
		 */
		public function invoke(object $instance, string $methodName, array $parameters = []): mixed;
	}