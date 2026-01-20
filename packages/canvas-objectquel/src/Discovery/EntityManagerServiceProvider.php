<?php
	
	namespace Quellabs\CanvasObjectQuel\Discovery;
	
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	
	/**
	 * Registers a singleton ObjectQuel EntityManager instance with the dependency injection
	 * container. This provider integrates ObjectQuel ORM with Canvas by injecting the shared
	 * database connection from canvas-database package.
	 */
	class EntityManagerServiceProvider extends BaseServiceProvider {
		
		/**
		 * Cached singleton instance of the EntityManager
		 * @var EntityManager|null
		 */
		private static ?EntityManager $instance = null;
		
		/**
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array $metadata Metadata for filtering
		 * @return bool True if this provider supports the EntityManager class
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === EntityManager::class;
		}
		
		/**
		 * Creates a new EntityManager instance with autowired dependencies
		 * @param string $className The class name to instantiate (EntityManager)
		 * @param array $dependencies Autowired dependencies [Configuration, Connection]
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContext|null $methodContext Optional method context
		 * @return object A configured EntityManager instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext = null): object {
			// Return existing instance if already created (singleton behavior)
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Pass dependencies to constructor
			self::$instance = new $className(...$dependencies);
			
			// Return the singleton instance
			return self::$instance;
		}
	}