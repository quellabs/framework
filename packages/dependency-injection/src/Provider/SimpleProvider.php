<?php
	
	namespace Quellabs\DependencyInjection\Provider;
	
	use Quellabs\Contracts\Context\MethodContext;
	
	/**
	 * Simple binding provider for cases where you just need interface-to-concrete
	 * mapping with no custom instantiation logic. This class eliminates boilerplate
	 * for straightforward bindings where the concrete class can be instantiated
	 * directly with autowired dependencies.
	 */
	class SimpleBinding extends ServiceProvider {
		
		/** @var string The interface or abstract class to bind */
		private string $abstract;
		
		/** @var string The concrete class to instantiate */
		private string $concrete;
		
		/**
		 * Constructor
		 * @param string $abstract The interface or abstract class to bind
		 * @param string $concrete The concrete class to instantiate
		 */
		public function __construct(
			string $abstract,
			string $concrete
		) {
			$this->concrete = $concrete;
			$this->abstract = $abstract;
		}
		
		/**
		 * Supports the bound abstract type with no context requirements.
		 * @param string $className The class name being resolved
		 * @param array $metadata Context information (ignored for simple bindings)
		 * @return bool True if this binding handles the requested class
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === $this->abstract;
		}
		
		/**
		 * Creates an instance of the concrete class with autowired dependencies.
		 * @param string $className The class name being instantiated (will be the abstract type)
		 * @param array $dependencies Pre-resolved constructor dependencies from autowiring
		 * @param array $metadata Additional metadata (unused in simple bindings)
		 * @param MethodContext|null $methodContext Optional method context (unused in simple bindings)
		 * @return object The instantiated concrete class
		 */
		public function createInstance(
			string $className,
			array $dependencies,
			array $metadata,
			?MethodContext $methodContext = null
		): object {
			// Instantiate the concrete class with autowired dependencies
			return new $this->concrete(...$dependencies);
		}
		
		/**
		 * Get the abstract type (interface/abstract class) this binding handles.
		 * @return string The abstract type
		 */
		public function getAbstract(): string {
			return $this->abstract;
		}
		
		/**
		 * Get the concrete type this binding resolves to.
		 * @return string The concrete class name
		 */
		public function getConcrete(): string {
			return $this->concrete;
		}
	}