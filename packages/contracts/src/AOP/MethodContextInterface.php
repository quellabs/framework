<?php
	
	namespace Quellabs\Contracts\AOP;

	use Quellabs\Contracts\Context\MethodContextInterface as BaseMethodContextInterface;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Interface for context objects that encapsulate information about a method call.
	 * Used in AOP (Aspect-Oriented Programming) scenarios to provide
	 * interceptors and decorators with complete method execution context.
	 */
	interface MethodContextInterface extends BaseMethodContextInterface {
		
		/**
		 * Get the class object
		 * @return object The class being called
		 */
		public function getClass(): object;

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
	}