<?php
	
	namespace Quellabs\CanvasObjectQuel\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 * @Target("METHOD")
	 * ResolveEntity annotation class for ORM <--> Canvas glue
	 */
	class ResolveEntity implements AnnotationInterface {
		
		/** @var class-string */
		private string $entityClass;
		private string $routeParam;
		
		/** @var array<string, mixed> */
		private array $parameters;
		
		/**
		 * Column constructor
		 * @param array<string, mixed> $parameters Associative array of column parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$entityClass = $parameters['value'] ?? null;
			$routeParam = $parameters['routeParam'] ?? 'id';
			
			if (!is_string($entityClass) || !class_exists($entityClass)) {
				throw new \InvalidArgumentException('ResolveEntity requires a valid entity class as first argument');
			}
			
			if (!is_string($routeParam)) {
				throw new \InvalidArgumentException('ResolveEntity annotation requires "routeParam" to be string');
			}
			
			$this->entityClass = $entityClass;
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
		 * @return class-string
		 */
		public function getEntityClass(): string {
			return $this->entityClass;
		}
		
		/**
		 * Gets the routeParam name
		 * @return string
		 */
		public function getRouteParam(): string {
			return $this->routeParam;
		}
	}