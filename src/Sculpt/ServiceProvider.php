<?php
	
	namespace App\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		public function register(Application $application): void {
			$this->registerCommands($application, [
				MakeDiscoveryMapping::class
			]);
		}
	}