<?php

	namespace Quellabs\CanvasDoctrine\Annotations;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 * @Target("METHOD")
	 * Maps a controller parameter to a route key.
	 */
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class ResolveEntity implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		private string $parameter;
		private string $routeParam;

		/**
		 * Stores the binding names.
		 * @param string $parameter
		 * @param string $routeParam
		 * @return void
		 * @throws \InvalidArgumentException
		 */
		public function __construct(string $parameter, string $routeParam) {
			if ($parameter === '' || $routeParam === '') {
				throw new \InvalidArgumentException('Binding names must be non-empty');
			}
			$this->parameter = $parameter;
			$this->routeParam = $routeParam;
		}

		/**
		 * Returns the controller parameter name.
		 * @return string
		 */
		public function getParameter(): string {
			return $this->parameter;
		}

		/**
		 * Returns the route key.
		 * @return string
		 */
		public function getRouteParam(): string {
			return $this->routeParam;
		}
	}
