<?php
	
	namespace App\Services;
	
	use App\Entities\LocationLog;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\SignalHub\HasSignals;
	use Quellabs\SignalHub\Signal;
	
	class LocationTracker {
		use HasSignals;
		
		public Signal $locationLogged;
		public Signal $locationProcessed;
		
		private array $queuedLocations = [];
		private array $config = [];
		private EntityManager $em;
		
		public function __construct(
			EntityManager $em,
			array         $config = []
		) {
			$this->em = $em;
			$this->locationLogged = $this->createSignal([\App\Entities\LocationLog::class], 'location.logged');
			$this->locationProcessed = $this->createSignal(['array'], 'location.processed');
			
			$this->config = array_merge([
				'batch_size'         => 100,
				'retention_days'     => 30,
				'accuracy_threshold' => 100, // meters
				'enable_queuing'     => false
			], $config);
		}
		
		/**
		 * Log a location immediately to the database
		 */
		public function logLocation(
			int    $userId,
			float  $latitude,
			float  $longitude,
			?float $accuracy = null,
			?float $altitude = null,
			?float $heading = null,
			?float $speed = null,
			array  $metadata = []
		): LocationLog {
			// Create location log entry
			$location = new LocationLog();
			$location->setUserId($userId);
			$location->setLatitude($latitude);
			$location->setLongitude($longitude);
			$location->setAccuracy($accuracy);
			$location->setAltitude($altitude);
			$location->setHeading($heading);
			$location->setSpeed($speed);
			$location->setMetadata($metadata);
			$location->setTimestamp(new \DateTime());
			
			// Filter by accuracy if configured
			if ($accuracy && $this->config['accuracy_threshold'] > 0 &&
				$accuracy > $this->config['accuracy_threshold']) {
				// Skip logging inaccurate locations
				return $location; // Return unsaved location
			}
			
			// Save to database
			$this->em->persist($location);
			$this->em->flush();
			
			// Emit signal for location logged
			$this->locationLogged->emit($location);
			
			return $location;
		}
		
		/**
		 * Queue a location for batch processing
		 */
		public function queueLocation(
			int    $userId,
			float  $latitude,
			float  $longitude,
			?float $accuracy = null,
			array  $metadata = []
		): void {
			if (!$this->config['enable_queuing']) {
				// Queue not enabled, log immediately
				$this->logLocation(
					userId: $userId,
					latitude: $latitude,
					longitude: $longitude,
					accuracy: $accuracy,
					metadata: $metadata
				);
				
				return;
			}
			
			$this->queuedLocations[] = [
				'user_id'   => $userId,
				'latitude'  => $latitude,
				'longitude' => $longitude,
				'accuracy'  => $accuracy,
				'metadata'  => $metadata,
				'timestamp' => new \DateTime()
			];
			
			// Process queue if it reaches batch size
			if (count($this->queuedLocations) >= $this->config['batch_size']) {
				$this->processQueuedLocations();
			}
		}
		
		/**
		 * Process all queued locations (used by background task)
		 */
		public function processQueuedLocations(): int {
			if (empty($this->queuedLocations)) {
				return 0;
			}
			
			$processed = 0;
			$locations = [];
			
			foreach ($this->queuedLocations as $queuedLocation) {
				$location = new LocationLog();
				$location->setUserId($queuedLocation['user_id']);
				$location->setLatitude($queuedLocation['latitude']);
				$location->setLongitude($queuedLocation['longitude']);
				$location->setAccuracy($queuedLocation['accuracy']);
				$location->setMetadata($queuedLocation['metadata']);
				$location->setTimestamp($queuedLocation['timestamp']);
				
				$this->em->persist($location);
				$locations[] = $location;
				$processed++;
			}
			
			$this->em->flush();
			
			// Clear the queue
			$this->queuedLocations = [];
			
			// Emit signal for batch processed
			$this->locationProcessed->emit($locations);
			
			return $processed;
		}
		
		/**
		 * Get the last location for a user
		 */
		public function getLastLocation(int $userId): ?LocationLog {
			$results = $this->em->executeQuery("
	            range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
	            retrieve l where l.userId = :userId
	            sort by l.timestamp desc
	            window 0 using window_size 1
	        ", [
				'userId' => $userId
			]);
			
			return !empty($results) ? $results[0]['l'] : null;
		}
		
		/**
		 * Get location history for a user
		 */
		public function getLocationHistory(
			int        $userId,
			int        $limit = 100,
			?\DateTime $since = null
		): array {
			$params = ['userId' => $userId];
			$whereClause = 'l.userId = :userId';
			
			if ($since) {
				$whereClause .= ' and l.timestamp >= :since';
				$params['since'] = $since->format('Y-m-d H:i:s');
			}
			
			return $this->em->getCol("
                range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
                retrieve (l)
                where {$whereClause}
                sort by l.timestamp desc
                window 0 using window_size {$limit}
	        ", $params);
		}
		
		/**
		 * Clean up old location logs (called by scheduled task)
		 */
		public function cleanupOldLogs(): int {
			// Retention disabled
			if ($this->config['retention_days'] <= 0) {
				return 0;
			}
			
			// Create date
			$cutoffDate = new \DateTime();
			$cutoffDate->modify("-{$this->config['retention_days']} days");
			
			// Use raw SQL for efficient bulk delete
			$connection = $this->em->getConnection();
			
			$stmt = $connection->execute("
            	DELETE FROM `location_logs`
            	WHERE `timestamp` < :cutoff_date
        	", [
				'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s')
			]);
			
			return $stmt->rowCount();
		}
		
		/**
		 * Generate location analytics (called by scheduled task)
		 * @return array
		 * @throws \DateMalformedStringException
		 * @throws \Quellabs\ObjectQuel\ObjectQuel\QuelException
		 */
		public function generateAnalytics(): array {
			// Get all locations from the last 24 hours and group by user in PHP
			$yesterday = new \DateTime();
			$yesterday->modify('-24 hours');
			
			$results = $this->em->executeQuery("
                range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
                retrieve (l)
                where l.timestamp >= :since
                sort by l.userId asc
            ", [
				'since' => $yesterday->format('Y-m-d H:i:s')
			]);
			
			// Group by user ID in PHP (since ObjectQuel has no GROUP BY)
			$userCounts = [];
			$totalLocations = 0;
			
			foreach ($results as $result) {
				$location = $result['l'];
				$userId = $location->getUserId();
				
				if (!isset($userCounts[$userId])) {
					$userCounts[$userId] = 0;
				}
				
				$userCounts[$userId]++;
				$totalLocations++;
			}
			
			// Sort by location count (highest first)
			arsort($userCounts);
			
			$analytics = [
				'period'          => '24_hours',
				'generated_at'    => new \DateTime(),
				'user_activity'   => [],
				'total_locations' => $totalLocations
			];
			
			foreach ($userCounts as $userId => $count) {
				$analytics['user_activity'][] = [
					'user_id'        => $userId,
					'location_count' => $count
				];
			}
			
			return $analytics;
		}
		
		/**
		 * Get location statistics for a user using ObjectQuel aggregate functions
		 * @param int $userId
		 * @param int $days
		 * @return array
		 * @throws \DateMalformedStringException
		 * @throws \Quellabs\ObjectQuel\ObjectQuel\QuelException
		 */
		public function getUserLocationStats(int $userId, int $days = 7): array {
			// Create date
			$since = new \DateTime();
			$since->modify("-{$days} days");
			
			// Get total count
			$countResults = $this->em->executeQuery("
                range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
                retrieve (count(l.id))
                where l.userId = :userId and l.timestamp >= :since
            ", [
				'userId' => $userId,
				'since'  => $since->format('Y-m-d H:i:s')
			]);
			
			$totalLocations = $countResults[0]['count(l.id)'] ?? 0;
			
			if ($totalLocations === 0) {
				return [
					'total_locations' => 0,
					'avg_accuracy'    => 0,
					'first_location'  => null,
					'last_location'   => null,
					'period_days'     => $days
				];
			}
			
			// Get average accuracy (only for non-null accuracy values)
			$avgResults = $this->em->executeQuery("
                range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
                retrieve (avg(l.accuracy))
                where l.userId = :userId and
                      l.timestamp >= :since and
                      l.accuracy is not null
            ", [
				'userId' => $userId,
				'since'  => $since->format('Y-m-d H:i:s')
			]);
			
			// Get first and last timestamps
			$timestampResults = $this->em->executeQuery("
                range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
                retrieve (min(l.timestamp), max(l.timestamp))
                where l.userId = :userId and l.timestamp >= :since
            ", [
				'userId' => $userId,
				'since'  => $since->format('Y-m-d H:i:s')
			]);
			
			$avgAccuracy = $avgResults[0]['avg(l.accuracy)'] ?? 0;
			$firstLocation = $timestampResults[0]['min(l.timestamp)'] ?? null;
			$lastLocation = $timestampResults[0]['max(l.timestamp)'] ?? null;
			
			return [
				'total_locations' => $totalLocations,
				'avg_accuracy'    => $avgAccuracy ? round($avgAccuracy, 2) : 0,
				'first_location'  => $firstLocation,
				'last_location'   => $lastLocation,
				'period_days'     => $days
			];
		}
		
		/**
		 * Check if user is currently being tracked
		 */
		public function isUserTracked(int $userId, int $timeoutMinutes = 15): bool {
			$timeout = new \DateTime();
			$timeout->modify("-{$timeoutMinutes} minutes");
			
			$results = $this->em->executeQuery("
	            range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
	            retrieve (l)
	            where l.userId = :userId and l.timestamp >= :timeout
	            window 0 using window_size 1
	        ", [
				'userId'  => $userId,
				'timeout' => $timeout->format('Y-m-d H:i:s')
			]);
			
			return !empty($results);
		}
	}