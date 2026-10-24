<?php
	
	namespace Quellabs\AnnotationReader\Tests\Fixtures\Services;

	use Quellabs\AnnotationReader\Tests\Fixtures\Annotations\AnotherTest;
	use Quellabs\AnnotationReader\Tests\Fixtures\Annotations\CacheableTest;
	use Quellabs\AnnotationReader\Tests\Fixtures\Annotations\Test;

	/**
	 * @CacheableTest(ttl=3600)
	 * @AnotherTest(id=100)
	 */
	#[CacheableTest(ttl: 3600)]
	#[AnotherTest(id: 100)]
	abstract class BaseService {
		/**
		 * @Test(name="parent_method")
		 */
		#[Test(name: 'parent_method')]
		public function annotatedMethod(): void {}
		
		/**
		 * @Test(name="parent_property")
		 */
		#[Test(name: 'parent_property')]
		public mixed $annotatedProperty;
	}
