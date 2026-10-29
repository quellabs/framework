<?php

	namespace Quellabs\Canvas\Tests\Fixtures\EntityBindingControllers;

	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Tests\Fixtures\Entities\EntityBindingPostEntity;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Fixture controller for EntityBindingRequestDispatchTest. Never scanned by
	 * real route discovery (that test feeds an already-matched route directly
	 * to RequestHandler::executeCanvasRoute()); the @Route annotations exist
	 * only so canvas-objectquel's ServiceProvider has the parameter names to
	 * bind by. "show" binds a required parameter implicitly by name;
	 * "showOptional" is the nullable counterpart.
	 */
	class EntityBindingPostController {

		/**
		 * Required entity parameter: resolves to a 404 Response when no row
		 * matches the route's {slug} value.
		 * @Route("/entity-binding-posts/{slug}", methods={"GET"})
		 * @param EntityBindingPostEntity $slug
		 * @return Response
		 */
		public function show(EntityBindingPostEntity $slug): Response {
			return new Response($slug->getTitle());
		}

		/**
		 * Nullable entity parameter: receives null when no row matches the
		 * route's {slug} value, instead of a 404.
		 * @Route("/entity-binding-posts-optional/{slug}", methods={"GET"})
		 * @param EntityBindingPostEntity|null $slug
		 * @return Response
		 */
		public function showOptional(?EntityBindingPostEntity $slug): Response {
			return new Response($slug === null ? 'not-found' : $slug->getTitle());
		}
	}
