<?php
	
	namespace Quellabs\GeoFencing\Controllers;
	
	use App\Services\GeoFenceService;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\GeoFencing\Services\LocationTracker;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\Canvas\Annotations\{Route, RoutePrefix, InterceptWith};
	use Quellabs\Canvas\Validation\ValidateAspect;
	use App\Validation\LocationValidation;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	
	/**
	 * @RoutePrefix("/api/location")
	 * @InterceptWith(LocationTrackingAspect::class)
	 */
	class LocationController extends BaseController {
		
		private GeoFenceService $geoFenceService;
		private LocationTracker $locationTracker;
		
		public function __construct(
			?TemplateEngineInterface $templateEngine,
			?EntityManager $entityManager,
			GeoFenceService $geoFenceService,
			LocationTracker $locationTracker
		) {
			$this->locationTracker = $locationTracker;
			$this->geoFenceService = $geoFenceService;
			parent::__construct($templateEngine, $entityManager);
		}
		
		/**
		 * @Route("/update", methods={"POST"})
		 * @InterceptWith(ValidateAspect::class, validator=LocationValidation::class, auto_respond=true)
		 */
		public function updateLocation(Request $request) {
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
		public function locationHistory(Request $request) {
			$userId = $this->getCurrentUserId();
			$limit = (int)$request->query->get('limit', 100);
			
			$locations = $this->em->executeQuery("
            range of l is Quellabs\\GeoFencing\\Entities\\LocationLog
            retrieve l where l.userId = :userId
            sort by l.timestamp desc
            limit :limit
        ", ['userId' => $userId, 'limit' => $limit]);
			
			return $this->json($locations);
		}
		
		/**
		 * @Route("/events", methods={"GET"})
		 */
		public function fenceEvents(Request $request): JsonResponse {
			$userId = $this->getCurrentUserId();
			$limit = (int)$request->query->get('limit', 50);
			
			$events = $this->em->executeQuery("
                range of e is Quellabs\\GeoFencing\\Entities\\FenceEvent
                range of f is Quellabs\\GeoFencing\\Entities\\GeoFence via e.fenceId
                retrieve (e, f.name)
                where e.userId = :userId
                sort by e.timestamp desc
                limit {$limit}
            ", [
				'userId' => $userId,
				'limit'  => $limit
			]);
			
			return $this->json($events);
		}
	}