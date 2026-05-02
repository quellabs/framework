<?php
	
	namespace Quellabs\AnnotationReader\AnnotationTest;
	
	/**
	 * @AnotherTest(id=200, priority="high")
	 */
	class UserService extends BaseService {
		/**
		 * @Test(name="child_method")
		 */
		public function annotatedMethod(): void {}
		
		/**
		 * @Test(name="child_property")
		 */
		public mixed $annotatedProperty;
	}