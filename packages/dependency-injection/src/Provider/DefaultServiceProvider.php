<?php
	
	namespace Quellabs\DependencyInjection\Provider;
	
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
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
		 */
		public function __construct() {
			$this->discovery = new Discover();
			$this->discovery->addScanner(new ComposerScanner());
		}
		
		/**
		 * Supports all classes as a fallback
		 * @param string $className
		 * @param array $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return true;
		}
		
		/**
		 * Create instance with basic instantiation using singleton pattern
		 * @param string $className The class to instantiate
		 * @param array $dependencies Pre-resolved constructor dependencies
		 * @param MethodContext|null $methodContext
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies, ?MethodContext $methodContext=null): object {
			// Check if the class exists
			if (!class_exists($className)) {
				throw new \RuntimeException("Class '$className' does not exist");
			}
			
			// Check if this class implements ProviderInterface
			if (is_subclass_of($className, ProviderInterface::class)) {
				return $this->createServiceProvider($className);
			}
			
			// If the instance already exists, return it
			if (isset($this->instances[$className])) {
				return $this->instances[$className];
			}
			
			// Create a new instance using the parent method
			$instance = parent::createInstance($className, $dependencies, $methodContext);
			
			// Store the instance for future use
			$this->instances[$className] = $instance;
			
			// Return the instance
			return $instance;
		}
		
		/**
		 * Create or retrieve a service provider instance using Discovery
		 * @param string $className The fully qualified class name of the service provider
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
				return $this->discovery->get($className);
			}
			
			// Throw exception if provider cannot be found in any discovered definitions
			throw new \RuntimeException("Cannot instantiate service provider: {$className}");
		}
	}