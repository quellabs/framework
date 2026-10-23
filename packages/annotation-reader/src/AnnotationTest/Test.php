<?php
	
	namespace Quellabs\AnnotationReader\AnnotationTest;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY)]
	class Test implements AnnotationInterface {
		use ReflectsAnnotationParameters;

		private string $name;
		
		/**
		 * Names the annotated fixture member.
		 * @param string $name
		 * @return void
		 */
		public function __construct(string $name) {
			$this->name = $name;
		}

		/**
		 * Returns the fixture member name.
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}
	}
