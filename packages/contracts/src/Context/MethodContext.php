<?php

	// Base shared interface
	namespace Quellabs\Contracts\Context;
	
	/**
	 * Base interface for method execution context
	 */
	interface MethodContext {
		
		/**
		 * Get the class name
		 * @return object The class being called
		 */
		public function getClass(): object;
		
		/**
		 * Get the name of the method being called
		 * @return string The method name
		 */
		public function getMethodName(): string;
	}