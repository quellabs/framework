<?php

	namespace Quellabs\Contracts\Context;
	
	use Symfony\Component\HttpFoundation\Request;
	
	/**
	 * Base interface for method execution context
	 */
	interface MethodContextInterface {
		
		/**
		 * Get the class name
		 * @return class-string The class name
		 */
		public function getClassName(): string;
		
		/**
		 * Get the name of the method being called
		 * @return string The method name
		 */
		public function getMethodName(): string;
		
		/**
		 * Get all arguments passed to the method.
		 * @return array<string, mixed> Array of method arguments in order
		 */
		public function getArguments(): array;

	}