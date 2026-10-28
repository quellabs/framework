<?php

	namespace Quellabs\CanvasObjectQuel\Exceptions;

	use Quellabs\Canvas\Exceptions\HttpException;

	/**
	 * Thrown when a controller parameter is configured to bind to an entity but
	 * the route has no value for the binding, or the value has a shape the
	 * resolver does not support (e.g. a composite-key array). This is a
	 * configuration/programming mistake, not a missing record, so it carries
	 * HTTP code 500 via HttpException rather than being treated as a 404.
	 */
	class EntityBindingException extends HttpException {

		/**
		 * @param string $className Controller class that declares the parameter
		 * @param string $methodName Method that declares the parameter
		 * @param string $parameterName Controller parameter being bound
		 * @param string $routeParam Route key the parameter is bound to
		 * @param string $entityClass Entity class the parameter should resolve to
		 * @param string $reason Human-readable reason the binding could not proceed
		 */
		public function __construct(
			string $className,
			string $methodName,
			string $parameterName,
			string $routeParam,
			string $entityClass,
			string $reason
		) {
			parent::__construct(
				"Cannot bind parameter '{$parameterName}' of {$className}::{$methodName}() to entity " .
				"{$entityClass}: {$reason} (route key '{$routeParam}')",
				500
			);
		}
	}
