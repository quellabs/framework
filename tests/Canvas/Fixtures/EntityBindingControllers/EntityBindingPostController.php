<?php

	namespace Quellabs\Canvas\Tests\Fixtures\EntityBindingControllers;

	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Tests\Fixtures\Entities\EntityBindingPostEntity;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Fixture controller for the real HTTP end-to-end entity-binding test.
	 * Lives in its own scanned directory so route discovery sees only these
	 * two routes. "show" binds a required parameter implicitly by name;
	 * "showOptional" is the nullable counterpart.
	 */
	class EntityBindingPostController {

		/**
		 * @Route("/entity-binding-posts/{slug}", methods={"GET"})
		 */
		public function show(EntityBindingPostEntity $slug): Response {
			return new Response($slug->getTitle());
		}

		/**
		 * @Route("/entity-binding-posts-optional/{slug}", methods={"GET"})
		 */
		public function showOptional(?EntityBindingPostEntity $slug): Response {
			return new Response($slug === null ? 'not-found' : $slug->getTitle());
		}
	}
