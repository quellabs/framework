<?php
	
	namespace Quellabs\AnnotationReader\AnnotationTest;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	#[\Attribute(\Attribute::TARGET_CLASS)]
	class CacheableTest implements AnnotationInterface {
		use ReflectsAnnotationParameters;

		private int $ttl;
		
		/**
		 * Sets the fixture cache lifetime.
		 * @param int $ttl
		 * @return void
		 */
		public function __construct(int $ttl) {
			$this->ttl = $ttl;
		}

		/**
		 * Returns the fixture cache lifetime.
		 * @return int
		 */
		public function getTtl(): int {
			return $this->ttl;
		}
	}
