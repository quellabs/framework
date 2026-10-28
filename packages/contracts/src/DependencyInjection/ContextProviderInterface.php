<?php

	namespace Quellabs\Contracts\DependencyInjection;

	use Quellabs\Contracts\Context\MethodContextInterface;

	/**
	 * Computes a runtime @WithContext context value. A class-string implementing
	 * this interface may be passed anywhere a literal context tag (e.g. "redis")
	 * is accepted; the autowirer resolves it at request time instead of using it
	 * as a literal.
	 */
	interface ContextProviderInterface {

		/**
		 * Computes the context value for the parameter this provider is attached to
		 * @param MethodContextInterface|null $methodContext Null outside a request cycle
		 * @return string
		 */
		public function resolve(?MethodContextInterface $methodContext): string;
	}
