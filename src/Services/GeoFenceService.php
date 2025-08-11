<?php
	namespace App\Services;
	
	use App\Entities\FenceEvent;
	use App\Entities\GeoFence;
	use Quellabs\SignalHub\HasSignals;
	use Quellabs\SignalHub\Signal;
	use Quellabs\ObjectQuel\EntityManager;
	
	/**
	 * Service for managing geographical fence boundaries and tracking user location events.
	 * Supports both circular and polygon fence shapes with entry/exit event detection.
	 */
	class GeoFenceService {
		use HasSignals;
		
		/** @var Signal Signal emitted when a user enters a fence */
		public Signal $fenceEntered;
		
		/** @var Signal Signal emitted when a user exits a fence */
		public Signal $fenceExited;
		
		/** @var GeometryCalculator Helper for geometric calculations */
		private GeometryCalculator $calculator;
		
		/** @var EntityManager Database entity manager */
		private EntityManager $em;
		
		/**
		 * Initialize the GeoFence service with required dependencies.
		 * @param GeometryCalculator $calculator Service for distance and polygon calculations
		 * @param EntityManager $em Database entity manager
		 */
		public function __construct(
			GeometryCalculator $calculator,
			EntityManager $em
		) {
			$this->em = $em;
			$this->calculator = $calculator;
			
			// Initialize signals for fence entry/exit events
			$this->fenceEntered = $this->createSignal([FenceEvent::class], 'fence.entered');
			$this->fenceExited = $this->createSignal([FenceEvent::class], 'fence.exited');
		}
		
		/**
		 * Check if a user's location triggers any fence entry or exit events.
		 * Compares current location against all active fences and user's previous state.
		 * @param int $userId The ID of the user to check
		 * @param float $lat Current latitude position
		 * @param float $lng Current longitude position
		 * @return array Array of FenceEvent objects for any triggered events
		 */
		public function checkLocation(int $userId, float $lat, float $lng): array {
			// Query the database for geofences
			$activeFences = $this->em->findBy(GeoFence::class, ['active' => true]);
			
			// Get all currently active geofences
			$events = [];
			
			foreach ($activeFences as $fence) {
				// Check if user is currently inside this fence
				$isInside = $this->isInsideFence($lat, $lng, $fence);
				
				// Check if user was previously inside this fence
				$wasInside = $this->wasUserInsideFence($userId, $fence->getId());
				
				// User entered the fence (is inside now, wasn't before)
				if ($isInside && !$wasInside) {
					$event = $this->createEvent($userId, $fence->getId(), 'enter', $lat, $lng);
					$this->fenceEntered->emit($event);
					$events[] = $event;
					continue;
				}
				
				// User exited the fence (was inside before, isn't now)
				if (!$isInside && $wasInside) {
					$event = $this->createEvent($userId, $fence->getId(), 'exit', $lat, $lng);
					$this->fenceExited->emit($event);
					$events[] = $event;
				}
			}
			
			return $events;
		}
		
		/**
		 * Create a new circular geofence with specified center point and radius.
		 * @param string $name Human-readable name for the fence
		 * @param float $centerLat Latitude of the circle center
		 * @param float $centerLng Longitude of the circle center
		 * @param int $radiusMeters Radius of the circle in meters
		 * @param array $metadata Optional additional data to store with the fence
		 * @return GeoFence The created and persisted fence entity
		 */
		public function createCircleFence(
			string $name,
			float $centerLat,
			float $centerLng,
			int $radiusMeters,
			array $metadata = []
		): GeoFence {
			// Create new geofence
			$fence = new GeoFence();
			$fence->setName($name);
			$fence->setCircleGeometry($centerLat, $centerLng, $radiusMeters);
			$fence->setMetadata($metadata);
			$fence->setCreatedAt(new \DateTime());
			$fence->setUpdatedAt(new \DateTime());
			
			// Save to database
			$this->em->persist($fence);
			$this->em->flush();
			
			return $fence;
		}
		
		/**
		 * Create a new polygon geofence defined by an array of coordinate points.
		 * @param string $name Human-readable name for the fence
		 * @param array $coordinates Array of [lat, lng] coordinate pairs defining the polygon vertices
		 * @param array $metadata Optional additional data to store with the fence
		 * @return GeoFence The created and persisted fence entity
		 */
		public function createPolygonFence(
			string $name,
			array $coordinates,
			array $metadata = []
		): GeoFence {
			// Create new geofence
			$fence = new GeoFence();
			$fence->setName($name);
			$fence->setPolygonGeometry($coordinates);
			$fence->setMetadata($metadata);
			$fence->setCreatedAt(new \DateTime());
			$fence->setUpdatedAt(new \DateTime());
			
			// Save to database
			$this->em->persist($fence);
			$this->em->flush();
			
			return $fence;
		}
		
		/**
		 * Determine if a given coordinate point is inside the specified fence boundary.
		 * Handles both circular and polygon fence types.
		 * @param float $lat Latitude to test
		 * @param float $lng Longitude to test
		 * @param GeoFence $fence The fence to test against
		 * @return bool True if the point is inside the fence, false otherwise
		 */
		private function isInsideFence(float $lat, float $lng, GeoFence $fence): bool {
			// For circular fences, check if distance from center is within radius
			if ($fence->isCircle()) {
				$distance = $this->calculator->calculateDistance(
					$lat, $lng,
					$fence->getCenterLat(), $fence->getCenterLng()
				);
				
				return $distance <= $fence->getRadiusMeters();
			}
			
			// For polygon fences, use point-in-polygon algorithm
			if ($fence->isPolygon()) {
				return $this->calculator->pointInPolygon($lat, $lng, $fence->getCoordinates());
			}
			
			// Unknown fence type
			return false;
		}
		
		/**
		 * Check if a user was previously inside a fence based on their last recorded event.
		 * Looks up the most recent fence event for this user/fence combination.
		 *
		 * @param int $userId The user ID to check
		 * @param int $fenceId The fence ID to check
		 * @return bool True if user's last event was 'enter', false otherwise
		 */
		private function wasUserInsideFence(int $userId, int $fenceId): bool {
			// Query for the most recent fence event for this user/fence pair
			$lastEvent = $this->em->executeQuery("
                range of e is App\\Entities\\FenceEvent
                retrieve (e)
                where e.userId = :userId and e.fenceId = :fenceId
                sort by e.timestamp desc
                window 0 using window_size 1
            ", [
				'userId'  => $userId,
				'fenceId' => $fenceId
			]);
			
			// User is considered inside if their last event was an 'enter' event
			return !empty($lastEvent) && $lastEvent[0]['e']->getEventType() === 'enter';
		}
		
		/**
		 * Create and persist a new fence event record.
		 * Records user location and event details for tracking fence interactions.
		 * @param int $userId The user who triggered the event
		 * @param int $fenceId The fence that was entered or exited
		 * @param string $eventType Either 'enter' or 'exit'
		 * @param float $lat Latitude where the event occurred
		 * @param float $lng Longitude where the event occurred
		 * @return FenceEvent The created and persisted event entity
		 */
		private function createEvent(
			int $userId,
			int $fenceId,
			string $eventType,
			float $lat,
			float $lng
		): FenceEvent {
			$event = new FenceEvent();
			$event->setUserId($userId);
			$event->setFenceId($fenceId);
			$event->setEventType($eventType);
			$event->setLatitude($lat);
			$event->setLongitude($lng);
			$event->setTimestamp(new \DateTime());
			
			// Save to database immediately
			$this->em->persist($event);
			$this->em->flush();
			
			return $event;
		}
	}