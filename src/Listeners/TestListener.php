<?php
	
	namespace App\Listeners;
	
	use Quellabs\Canvas\Annotations\ListenTo;
	
	class TestListener {
		
		/**
		 * @ListenTo("test")
		 * @return int
		 */
		public function test(): int {
			return 10;
		}
	}
	
	
