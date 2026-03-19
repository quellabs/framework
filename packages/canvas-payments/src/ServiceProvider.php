<?php
	
	namespace Quellabs\Payments;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	
	/**
	 * Registers a singleton PaymentRouter instance with the dependency injection container.
	 * Satisfies both PaymentProviderInterface and ServiceProvider injection points,
	 * ensuring all consumers share the same router instance.
	 */
	class ServiceProvider extends BaseServiceProvider {
		
		/**
		 * @var PaymentRouter
		 */
		private static PaymentRouter $instance;
		
		/**
		 * Returns true if this provider can satisfy the requested class.
		 * Handles PaymentProviderInterface (consumed by application code) and
		 * ServiceProvider itself (consumed by the DI container during bootstrap).
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return
				$className === PaymentProviderInterface::class ||
				$className === ServiceProvider::class;
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
			
			// Create and cache the singleton
			self::$instance = new PaymentRouter();
			
			// Return the singleton instance
			return self::$instance;
		}
	}