<?php
	
	namespace App\Tasks;
	
	use App\Services\LocationTracker;
	use Quellabs\Contracts\TaskScheduler\AbstractTask;
	
	class LocationProcessingTask extends AbstractTask {
		
		private LocationTracker $locationTracker;
		
		public function __construct(LocationTracker $locationTracker) {
			$this->locationTracker = $locationTracker;
		}
		
		public function handle(): void {
			// Process queued location updates
			$this->locationTracker->processQueuedLocations();
			
			// Clean up old location logs
			$this->locationTracker->cleanupOldLogs();
			
			// Generate location-based analytics
			$this->locationTracker->generateAnalytics();
		}
		
		public function getDescription(): string {
			return "Process location updates and maintain location data";
		}
		
		public function getSchedule(): string {
			return "*/5 * * * *"; // Every 5 minutes
		}
		
		public function getName(): string {
			return "location-processing";
		}
		
		public function getTimeout(): int {
			return 300; // 5 minutes
		}
		
		public function enabled(): bool {
			return true;
		}
	}