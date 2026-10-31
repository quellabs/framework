<?php

	namespace Quellabs\CanvasEloquent\Annotations;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 * @Target("METHOD")
	 * Targets a controller parameter by name and maps it to a route parameter.
	 * Usage: @ResolveModel(parameter="post", routeParam="postId")
	 * Attribute usage: #[ResolveModel(parameter: "post", routeParam: "postId")]
	 */
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class ResolveModel implements AnnotationInterface {
		use ReflectsAnnotationParameters;

		private string $parameter;
		private string $routeParam;

		/**
		 * Constructs the ResolveModel annotation.
		 * @param string $parameter
		 * @param string $routeParam
		 * @return void
		 */
		public function __construct(string $parameter, string $routeParam) {
			if ($parameter === '') {
				throw new \InvalidArgumentException('ResolveModel annotation requires a non-empty "parameter" string');
			}

			if ($routeParam === '') {
				throw new \InvalidArgumentException('ResolveModel annotation requires a non-empty "routeParam" string');
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
