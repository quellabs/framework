<?php
	
	namespace Quellabs\Payments;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	use Quellabs\Payments\Contracts\PaymentInterface;
	
	/**
	 * Registers a singleton PaymentRouter instance with the dependency injection container.
	 * Satisfies both PaymentInterface and ServiceProvider injection points,
	 * ensuring all consumers share the same router instance.
	 */
	class ServiceProvider extends BaseServiceProvider {
		
		/**
		 * @var PaymentInterface|null
		 */
		private static ?PaymentInterface $instance = null;
		
		/**
		 * Returns true if this provider can satisfy the requested class.
		 * Handles PaymentInterface (consumed by application code) and
		 * PaymentRouter (consumed by the DI container during bootstrap).
		 * @param string $className The fully qualified class name to check
		 * @param array<string, mixed> $metadata Metadata for filtering
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return
				$className === PaymentInterface::class ||
				$className === PaymentRouter::class;
		}
		
		/**
		 * Returns the singleton PaymentRouter instance, creating it on first call.
		 * @param string $className The class name requested by the container
		 * @param array<string, mixed> $dependencies Additional autowired dependencies (currently unused)
		 * @param array<string, mixed> $metadata Metadata as passed by Discover
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