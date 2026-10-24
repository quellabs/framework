<?php
	
	namespace Quellabs\AnnotationReader;
	
	interface AnnotationInterface {
		
		/**
		 * Returns all parameters
		 * @return array<string, mixed>
		 */
		public function getParameters(): array;
	}
