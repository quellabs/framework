<?php
	
	namespace Quellabs\CanvasObjectQuel\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	/**
	 * @Annotation
	 * @Target("METHOD")
	 * Targets a controller parameter by name and maps it to a route parameter.
	 * Usage: @ResolveEntity(parameter="post", routeParam="slug")
	 */
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class ResolveEntity implements AnnotationInterface {
		use ReflectsAnnotationParameters;

		private string $parameter;
		private string $routeParam;

		/**
		 * Constructs the ResolveEntity annotation.
		 * @param string $parameter
		 * @param string $routeParam
		 * @return void
		 */
		public function __construct(string $parameter, string $routeParam) {
			if ($parameter === '') {
				throw new \InvalidArgumentException('ResolveEntity annotation requires a non-empty "parameter" string');
			}

			if ($routeParam === '') {
				throw new \InvalidArgumentException('ResolveEntity annotation requires a non-empty "routeParam" string');
			}

			$this->parameter = $parameter;
			$this->routeParam = $routeParam;
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
