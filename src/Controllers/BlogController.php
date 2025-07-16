<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Debugbar\DebugbarAspect;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	
	class BlogController extends BaseController {
		
		/**
		 * @Route("/posts/")
		 * @InterceptWith(DebugbarAspect::class)
		 * @return Response
		 */
		public function index(): Response {
			$posts = $this->em->findBy(PostEntity::class, ['published' => true]);
			
			return $this->render("blog/index.tpl", [
				'posts' => $posts
			]);
		}
		
		/**
		 * @Route("/posts/{id:int}")
		 * @param int $id
		 * @return Response
		 */
		public function show(int $id): Response {
			$post = $this->em->find(PostEntity::class, $id);
			
			if (!$post) {
				return $this->notFound('Post does not exist.');
			}
			
			return $this->render("blog/show.tpl", [
				'post' => $post
			]);
		}
	}