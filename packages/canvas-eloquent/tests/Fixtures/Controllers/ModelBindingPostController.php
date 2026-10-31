<?php

	namespace Quellabs\CanvasEloquent\Tests\Fixtures\Controllers;

	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\CanvasEloquent\Annotations\ResolveModel;
	use Quellabs\CanvasEloquent\Tests\Fixtures\Models\ModelBindingPost;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Fixture controller for ModelBindingRequestDispatchTest. Never scanned by
	 * real route discovery (that test feeds an already-matched route directly
	 * to RequestHandler::executeCanvasRoute()); the @Route annotations exist
	 * only for documentation parity with the equivalent canvas-objectquel
	 * fixture. "show" binds a required parameter implicitly by name;
	 * "showOptional" is the nullable counterpart; "showByAnnotation" binds via
	 * an explicit @ResolveModel annotation parsed by the real AnnotationReader.
	 */
	class ModelBindingPostController {

		/**
		 * Required model parameter: resolves to a 404 Response when no row
		 * matches the route's {post} value.
		 * @Route("/model-binding-posts/{post}", methods={"GET"})
		 * @param ModelBindingPost $post
		 * @return Response
		 */
		public function show(ModelBindingPost $post): Response {
			return new Response($post->title);
		}

		/**
		 * Nullable model parameter: receives null when no row matches the
		 * route's {post} value, instead of a 404.
		 * @Route("/model-binding-posts-optional/{post}", methods={"GET"})
		 * @param ModelBindingPost|null $post
		 * @return Response
		 */
		public function showOptional(?ModelBindingPost $post): Response {
			return new Response($post === null ? 'not-found' : $post->title);
		}

		/**
		 * Explicit @ResolveModel binding: the route parameter is named
		 * {postId}, which differs from the controller parameter name
		 * ($post), so the annotation is required to map one to the other.
		 * @Route("/model-binding-posts-named/{postId}", methods={"GET"})
		 * @ResolveModel(parameter="post", routeParam="postId")
		 * @param ModelBindingPost $post
		 * @return Response
		 */
		public function showByAnnotation(ModelBindingPost $post): Response {
			return new Response($post->title);
		}
	}
