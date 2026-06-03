<?php
	
	namespace App\Listeners;
	
	use Quellabs\Canvas\Annotations\ListenTo;
	
	class TestListener {
		
		/**
		 * @ListenTo("tracking_params_received")
		 * @param mixed $v
		 * @return void
		 */
		public function test(mixed $v): void {
			var_dump($v);
		}
	}
	
	
