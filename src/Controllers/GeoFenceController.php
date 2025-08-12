<?php
	
	namespace App\Controllers;
	
	use App\Entities\GeoFence;
	use App\Services\GeoFenceService;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\Canvas\Annotations\{Route, RoutePrefix, InterceptWith};
	use Quellabs\Canvas\Validation\ValidateAspect;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	
	/**
	 * This controller handles CRUD operations for geofences including circular
	 * and polygon-based geographic boundaries. All endpoints return JSON responses.
	 * @RoutePrefix("/api/geofences")
	 */
	class GeoFenceController extends BaseController {
		
		/**
		 * Service layer for geofence business logic
		 */
		private GeoFenceService $geoFenceService;
		
		/**
		 * Constructor - initializes the controller with required dependencies
		 * @param Container $container
		 * @param GeoFenceService $geoFenceService Service for geofence-specific operations
		 */
		public function __construct(
			Container $container,
			GeoFenceService $geoFenceService
		) {
			$this->geoFenceService = $geoFenceService;
			parent::__construct($container);
		}
		
		/**
		 * Returns a list of all geofences that are currently active.
		 * Inactive/deleted geofences are filtered out.
		 * @Route("", methods={"GET"})
		 * @return JsonResponse Array of active GeoFence entities
		 */
		public function index(): JsonResponse {
			// Fetch only active geofences from the database
			$fences = $this->em()->findBy(GeoFence::class, ['active' => true]);
			return $this->json($fences);
		}
		
		/**
		 * Retrieves a single geofence entity. Returns 404 if the fence
		 * doesn't exist or has been soft-deleted.
		 * @Route("/{id:int}", methods={"GET"})
		 * @param int $id The geofence ID
		 * @return JsonResponse The GeoFence entity or error message
		 * @throws QuelException
		 */
		public function show(int $id): JsonResponse {
			// Find the geofence by primary key
			$fence = $this->em()->find(GeoFence::class, $id);
			
			// Return 404 if geofence doesn't exist
			if (!$fence) {
				return $this->json(['error' => 'Fence not found'], 404);
			}
			
			return $this->json($fence);
		}
		
		/**
		 * Creates a new circular geofence defined by a center point (lat/lng)
		 * and radius in meters. Request data is validated before processing.
		 * @Route("/circle", methods={"POST"})
		 * @InterceptWith(ValidateAspect::class, validator=GeoFenceValidation::class, auto_respond=true)
		 * @param Request $request HTTP request containing fence parameters
		 * @return JsonResponse The created GeoFence entity with 201 status
		 */
		public function createCircle(Request $request): JsonResponse {
			// Extract and type-cast request parameters for circle creation
			$fence = $this->geoFenceService->createCircleFence(
				$request->request->get('name'),                    // Fence name/identifier
				(float)$request->request->get('center_lat'),       // Center latitude coordinate
				(float)$request->request->get('center_lng'),       // Center longitude coordinate
				(int)$request->request->get('radius_meters'),      // Radius in meters
				
				$request->get('metadata', [])                      // Optional metadata array
			);
			
			// Return created fence with 201 Created status
			return $this->json($fence, 201);
		}
		
		/**
		 * Creates a new polygon-based geofence defined by an array of coordinate
		 * points that form the boundary shape. Request data is validated before processing.
		 * @Route("/polygon", methods={"POST"})
		 * @InterceptWith(ValidateAspect::class, validator=GeoFenceValidation::class, auto_respond=true)
		 * @param Request $request HTTP request containing fence parameters
		 * @return JsonResponse The created GeoFence entity with 201 status
		 */
		public function createPolygon(Request $request): JsonResponse {
			// Create polygon fence using coordinate array
			$fence = $this->geoFenceService->createPolygonFence(
				$request->get('name'),           // Fence name/identifier
				$request->get('coordinates'),    // Array of [lat, lng] coordinate pairs
				$request->get('metadata', [])    // Optional metadata array
			);
			
			// Return created fence with 201 Created status
			return $this->json($fence, 201);
		}
		
		/**
		 * Marks a geofence as inactive rather than physically deleting it from
		 * the database. This preserves historical data while removing it from
		 * active queries.
		 * @Route("/{id:int}", methods={"DELETE"})
		 * @param int $id The geofence ID to delete
		 * @return JsonResponse Success message or error if fence not found
		 */
		public function delete(int $id): JsonResponse {
			// Find the geofence to delete
			$fence = $this->em()->find(GeoFence::class, $id);
			
			// Return 404 if geofence doesn't exist
			if (!$fence) {
				return $this->json(['error' => 'Fence not found'], 404);
			}
			
			// Soft delete by setting active flag to false
			$fence->setActive(false);
			
			// Persist changes to database
			$this->em()->flush();
	
			// Return message to user
			return $this->json(['message' => 'Fence deleted']);
		}
	}