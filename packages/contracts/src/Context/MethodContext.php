<?php

	namespace Quellabs\Contracts\Context;
	
	/**
	 * Base interface for method execution context
	 */
	interface MethodContext {
		
		/**
		 * Get the class name
		 * @return string The class name
		 */
		public function getClassName(): string;
		
		/**
		 * Get the name of the method being called
		 * @return string The method name
		 */
		public function getMethodName(): string;
	}