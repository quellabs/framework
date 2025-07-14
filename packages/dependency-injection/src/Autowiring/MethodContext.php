<?php
	
	namespace Quellabs\DependencyInjection\Autowiring;
	
	/**
	 * This class implements the MethodContext contract and holds information about
	 * a specific method within a class instance, typically used during autowiring
	 * processes to provide context about which method is being processed.
	 */
	class MethodContext implements \Quellabs\Contracts\Context\MethodContext {
		
		/** @var string The class instance that contains the method */
		private string $class;
		
		/** @var string The name of the method being processed */
		private string $methodName;
		
		/**
		 * MethodContext constructor
		 * @param string $class The class instance that contains the method
		 * @param string $methodName The name of the method being processed
		 */
		public function __construct(string $class, string $methodName) {
			$this->class = $class;
			$this->methodName = $methodName;
		}
		
		/**
		 * Get the class instance
		 * @return object The class instance
		 */
		public function getClassName(): string {
			return $this->class;
		}
		
		/**
		 * Get the method name
		 * @return string The method name
		 */
		public function getMethodName(): string {
			return $this->methodName;
		}
	}