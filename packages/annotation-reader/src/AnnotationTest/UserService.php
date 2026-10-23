<?php
	
	namespace Quellabs\AnnotationReader\AnnotationTest;
	
	/**
	 * @AnotherTest(id=200, priority="high")
	 */
	#[AnotherTest(id: 200, priority: 'high')]
	class UserService extends BaseService {
		/**
		 * @Test(name="child_method")
		 */
		#[Test('child_method')]
		public function annotatedMethod(): void {}
		
		/**
		 * @Test(name="child_property")
		 */
		#[Test('child_property')]
		public mixed $annotatedProperty;
	}
