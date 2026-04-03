<?php
	
	namespace Quellabs\Shipments;
	
	use Quellabs\Canvas\Configuration\ConfigLoader;
	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	use Quellabs\Shipments\Contracts\ShipmentInterface;
	
	/**
	 * Registers a singleton PaymentRouter instance with the dependency injection container.
	 * Satisfies both PaymentProviderInterface and ServiceProvider injection points,
	 * ensuring all consumers share the same router instance.
	 */
	class ServiceProvider extends BaseServiceProvider {
		
		/**
		 * @var ShipmentRouter|null
		 */
		private static ?ShipmentRouter $instance = null;
		
		/**
		 * Dependency injection system
		 * @var Container
		 */
		private Container $container;
		
		/**
		 * ServiceProvider constructor
		 * @param Container $container
		 */
		public function __construct(Container $container) {
			$this->container = $container;
		}
		
		/**
		 * Returns true if this provider can satisfy the requested class.
		 * Handles ShipmentInterface (consumed by application code) and
		 * ServiceProvider itself (consumed by the DI container during bootstrap).
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return
				$className === ShipmentInterface::class ||
				$className === ShipmentRouter::class;
		}
		
		/**
		 * Returns the singleton PaymentRouter instance, creating it on first call.
		 * @param string $className The class name requested by the container
		 * @param array $dependencies Additional autowired dependencies (currently unused)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext Optional method context
		 * @return object The singleton PaymentRouter instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			// Return existing instance if already created (singleton behavior)
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Fetch config loader
			$configLoader = $this->container->get(ConfigProviderInterface::class);
			
			// Create and cache the singleton
			self::$instance = new ShipmentRouter($configLoader);
			
			// Return the singleton instance
			return self::$instance;
		}
	}