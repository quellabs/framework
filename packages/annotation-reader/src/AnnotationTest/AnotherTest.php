<?php
	
	namespace Quellabs\AnnotationReader\AnnotationTest;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	#[\Attribute(\Attribute::TARGET_CLASS)]
	class AnotherTest implements AnnotationInterface {
		use ReflectsAnnotationParameters;

		private int $id;
		private ?string $priority;
		
		/**
		 * Sets the fixture identifier and optional priority.
		 * @param int $id
		 * @param string|null $priority
		 * @return void
		 */
		public function __construct(int $id, ?string $priority = null) {
			$this->id = $id;
			$this->priority = $priority;
		}

		/**
		 * Returns the fixture identifier.
		 * @return int
		 */
		public function getId(): int {
			return $this->id;
		}

		/**
		 * Returns the optional fixture priority.
		 * @return string|null
		 */
		public function getPriority(): ?string {
			return $this->priority;
		}
	}
