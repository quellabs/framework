<?php

	namespace Quellabs\Canvas\Tests\Fixtures\RouteParameterCoercionControllers;

	use Quellabs\Canvas\Annotations\Route;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Fixture controller for RouteParameterCoercionDispatchTest. Route variables
	 * always arrive as strings (see VariableSegmentStrategy), so these methods
	 * exercise PHP's own weak-typing coercion at the actual method call, not
	 * entity binding.
	 */
	class RouteParameterCoercionController {

		/**
		 * @Route("/route-coercion-posts/{id}", methods={"GET"})
		 * @param int $id
		 * @return Response
		 */
		public function showById(int $id): Response {
			return new Response((string)($id + 1));
		}

		/**
		 * @Route("/route-coercion-posts/invalid/{id}", methods={"GET"})
		 * @param int $id
		 * @return Response
		 */
		public function showByInvalidId(int $id): Response {
			return new Response((string)($id + 1));
		}
	}
