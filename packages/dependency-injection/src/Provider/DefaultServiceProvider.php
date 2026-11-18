<?php
	
	namespace Quellabs\DependencyInjection\Provider;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\DependencyInjection\Exception\UnresolvableParameterException;
	
	class DefaultServiceProvider extends ServiceProvider {
		
		/**
		 * Store instances to ensure singleton behavior
		 * @var array<string, object>
		 */
		protected array $instances = [];
		
		/**
		 * Service discovery
		 * @var Discover
		 */
		private Discover $discovery;
		
		/**
		 * DefaultServiceProvider constructor
		 * @param Discover $discovery
		 */
		public function __construct(Discover $discovery) {
			$this->discovery = $discovery;
		}
		
		/**
		 * Supports all classes as a fallback
		 * @param class-string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return true;
		}
		
		/**
		 * Create instance with basic instantiation using singleton pattern
		 * @param class-string $className The class to instantiate
		 * @param array<int|string, mixed> $dependencies Pre-resolved constructor dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object
		 * @throws UnresolvableParameterException If $className does not exist, or exists only as an
		 *         interface/abstract class with no registered provider to build it
		 */
		public function createInstance(string $className, array $dependencies, array $metadata=[], ?MethodContextInterface $methodContext=null): object {
			// This fallback provider supports every class-string (see supports() below), so an
			// interface or abstract class reaching here means no specific provider is registered
			// for it. new $className(...) can only ever build a concrete class, so report that as
			// an unresolvable parameter rather than letting a non-existent-class RuntimeException or
			// a raw PHP "Cannot instantiate" Error escape — Autowirer already treats
			// UnresolvableParameterException as this one candidate failing, falling back to the
			// parameter's own default (or null, for a nullable parameter) instead of a hard failure.
			if (!class_exists($className) || !(new \ReflectionClass($className))->isInstantiable()) {
				throw new UnresolvableParameterException("Class '$className' does not exist or cannot be instantiated");
			}
			
			// Check if this class implements ProviderInterface
			if (is_subclass_of($className, ProviderInterface::class)) {
				/** @var class-string<ProviderInterface> $className */
				return $this->createServiceProvider($className);
			}
			
			// If the instance already exists, return it
			if (isset($this->instances[$className])) {
				return $this->instances[$className];
			}
			
			// Create a new instance using the parent method
			$instance = parent::createInstance($className, $dependencies, $metadata, $methodContext);
			
			// Store the instance for future use
			$this->instances[$className] = $instance;
			
			// Return the instance
			return $instance;
		}
		
		/**
		 * Create or retrieve a service provider instance using Discovery
		 * @param class-string<ProviderInterface> $className The fully qualified class name of the service provider
		 * @return object The instantiated and configured service provider
		 * @throws \RuntimeException If the service provider cannot be found or instantiated
		 */
		private function createServiceProvider(string $className): object {
			// Ensure discovery has been run to populate provider definitions
			// This is a lazy check - only runs discovery if not already done
			if (!$this->discovery->hasProviders()) {
				$this->discovery->discover();
			}
			
			// Check if the desired provider class exists in discovered definitions
			// If so, retrieve the provider instance with proper configuration and metadata
			if ($this->discovery->exists($className)) {
				// Instantiate the provider
				$provider = $this->discovery->get($className);
				
				// If it couldn't be instantiated, throw an exception
				if ($provider === null) {
					throw new \RuntimeException("Cannot instantiate service provider: {$className}");
				}
				
				return $provider;
			}
			
			// Throw exception if provider cannot be found in any discovered definitions
			throw new \RuntimeException("Cannot instantiate service provider: {$className}");
		}
	}