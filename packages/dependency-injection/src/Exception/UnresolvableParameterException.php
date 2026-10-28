<?php

	namespace Quellabs\DependencyInjection\Exception;

	/**
	 * Thrown when a single candidate type for a parameter could not be built —
	 * no provider instance, a missing class, or one of its own constructor
	 * parameters failed to autowire. Deliberately narrow so Autowirer can catch
	 * exactly this and move on to the next union candidate without swallowing
	 * a provider's annotation, database, or configuration failure.
	 */
	class UnresolvableParameterException extends \RuntimeException {
	}
