<?php
	
	namespace App\Controllers;
	
	use App\Services\GeoFenceService;
	use App\Services\LocationTracker;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\Canvas\Annotations\{Route, RoutePrefix, InterceptWith};
	use Quellabs\Canvas\Validation\ValidateAspect;
	use App\Validation\LocationValidation;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	
	/**
	 * @RoutePrefix("/api/location")
	 */
	class LocationController extends BaseController {
		
		private GeoFenceService $geoFenceService;
		private LocationTracker $locationTracker;
		
		public function __construct(
			Container $container,
			GeoFenceService          $geoFenceService,
			LocationTracker          $locationTracker
		) {
			$this->locationTracker = $locationTracker;
			$this->geoFenceService = $geoFenceService;
			parent::__construct($container);
		}
		
		public function getCurrentUserId(): int {
			return 1;
		}
		
		/**
		 * @Route("/update", methods={"POST"})
		 * @InterceptWith(ValidateAspect::class, validator=LocationValidation::class, auto_respond=true)
		 */
		public function updateLocation(Request $request): JsonResponse {
			$userId = $this->getCurrentUserId(); // Your auth implementation
			$lat = (float)$request->request->get('latitude');
			$lng = (float)$request->request->get('longitude');
			$accuracy = $request->request->get('accuracy');
			
			// Log the location
			$this->locationTracker->logLocation($userId, $lat, $lng, $accuracy);
			
			// Check for fence events
			$events = $this->geoFenceService->checkLocation($userId, $lat, $lng);
			
			return $this->json([
				'success'     => true,
				'events'      => $events,
				'fence_count' => count($events)
			]);
		}
		
		/**
		 * @Route("/history", methods={"GET"})
		 */
		public function locationHistory(Request $request): JsonResponse {
			$userId = $this->getCurrentUserId();
			$limit = (int)$request->query->get('limit', 100);
			
			$locations = $this->em()->executeQuery("
                range of l is App\\Entities\\LocationLog
                retrieve (l)
                where l.userId = :userId
                sort by l.timestamp desc
                window 0 using window_size {$limit}
            ", [
				'userId' => $userId
			]);
			
			return $this->json($locations);
		}
		
		/**
		 * @Route("/events", methods={"GET"})
		 */
		public function fenceEvents(Request $request): JsonResponse {
			$userId = $this->getCurrentUserId();
			$limit = (int)$request->query->get('limit', 50);
			
			$events = $this->em()->executeQuery("
                range of e is App\\Entities\\FenceEvent
                range of f is App\\Entities\\GeoFence via e.fenceId
                retrieve (e, f.name)
                where e.userId = :userId
                sort by e.timestamp desc
                window 0 using window_size {$limit}
            ", [
				'userId' => $userId
			]);
			
			return $this->json($events);
		}
	}