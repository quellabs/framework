<?php
	
	namespace Quellabs\CanvasObjectQuel\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 * @Target("METHOD")
	 * Targets a controller parameter by name and maps it to a route parameter.
	 * Usage: @ResolveEntity(parameter="post", routeParam="slug")
	 */
	class ResolveEntity implements AnnotationInterface {

		private string $parameter;
		private string $routeParam;

		/** @var array<string, mixed> */
		private array $parameters;

		/**
		 * ResolveEntity constructor
		 * @param array<string, mixed> $parameters Associative array of annotation parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$parameter = $parameters['parameter'] ?? null;
			$routeParam = $parameters['routeParam'] ?? 'id';

			if (!is_string($parameter) || $parameter === '') {
				throw new \InvalidArgumentException('ResolveEntity annotation requires a non-empty "parameter" string');
			}

			if (!is_string($routeParam)) {
				throw new \InvalidArgumentException('ResolveEntity annotation requires "routeParam" to be string');
			}

			$this->parameter = $parameter;
			$this->routeParam = $routeParam;
			$this->parameters = $parameters;
		}

		/**
		 * Returns all parameters for this annotation
		 * @return array<string, mixed> The complete parameters array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}

		/**
		 * Gets the name of the controller parameter this annotation targets
		 * @return string
		 */
		public function getParameter(): string {
			return $this->parameter;
		}

		/**
		 * Gets the routeParam name
		 * @return string
		 */
		public function getRouteParam(): string {
			return $this->routeParam;
		}
	}