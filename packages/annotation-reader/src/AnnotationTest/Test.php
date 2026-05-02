<?php
	
	namespace Quellabs\AnnotationReader\AnnotationTest;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class Test implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/**
		 * Table constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns the parameters for this annotation
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
	}