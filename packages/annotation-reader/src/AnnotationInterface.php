<?php
	
	namespace Quellabs\AnnotationReader;
	
	interface AnnotationInterface {
		
		/**
		 * Annotation constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters);
		
		/**
		 * Returns all parameters
		 * @return array<string, mixed>
		 */
		public function getParameters(): array;
	}