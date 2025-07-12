<?php
	
	namespace Quellabs\Contracts\AOP;
	
	use Symfony\Component\HttpFoundation\Request;
	
	/**
	 * Interface for context objects that encapsulate information about a method call.
	 * Used in AOP (Aspect-Oriented Programming) scenarios to provide
	 * interceptors and decorators with complete method execution context.
	 */
	interface MethodContext {
		
		/**
		 * Get the controller object
		 * @return object
		 */
		public function getController(): object;
		
		/**
		 * Get the name of the method being called.
		 * @return string The method name
		 */
		public function getMethod(): string;
		
		/**
		 * Get all arguments passed to the method.
		 * @return array Array of method arguments in order
		 */
		public function getArguments(): array;
		
		/**
		 * Get the ReflectionMethod object for accessing method metadata.
		 * Provides access to method visibility, parameters, return type, etc.
		 * @return \ReflectionMethod The reflection object for the method
		 */
		public function getReflection(): \ReflectionMethod;
		
		/**
		 * Returns the request object
		 * @return Request
		 */
		public function getRequest(): Request;
		
		/**
		 * Sets the request object
		 * @param Request $request
		 * @return void
		 */
		public function setRequest(Request $request): void;
		
		/**
		 * Returns the method arguments metadata
		 * @return array Array of parameter information including name, type, and default value
		 */
		public function getMethodArguments(): array;
	}