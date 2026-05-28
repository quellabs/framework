<?php
	
	namespace Quellabs\CanvasObjectQuel\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * ResolveEntity annotation class for ORM <--> Canvas glue
	 */
	class ResolveEntity implements AnnotationInterface {
		
		private string $target;
		private string $routeParam;
		
		/** @var array<string, mixed> */
		private array $parameters;
		
		/**
		 * Column constructor
		 * @param array<string, mixed> $parameters Associative array of column parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$target = $parameters['target'] ?? null;
			$routeParam = $parameters['routeParam'] ?? 'id';
			
			if (!is_string($target)) {
				throw new \InvalidArgumentException('ResolveEntity annotation requires a "target" parameter');
			}
			
			if (!is_string($routeParam)) {
				throw new \InvalidArgumentException('ResolveEntity annotation requires "routeParam" to be string');
			}
			
			$this->target = $target;
			$this->routeParam = $routeParam;
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns all parameters for this column annotation
		 * @return array<string, mixed> The complete parameters array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Gets the target name
		 * @return string
		 */
		public function getTarget(): string {
			return $this->target;
		}
		
		/**
		 * Gets the routeParam name
		 * @return string
		 */
		public function getRouteParam(): string {
			return $this->routeParam;
		}
	}