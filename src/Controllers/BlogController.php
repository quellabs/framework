<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Contracts\Cache\CacheInterface;
	use Symfony\Component\HttpFoundation\Response;
	use Quellabs\Contracts\Scheduler\QueueInterface;
	
	class BlogController extends BaseController {
		
		/**
		 * @Route("/posts/")
		 * @return Response
		 */
		public function index(): Response {
			$rs = $this->em()->executeQuery("
				range of x is PostEntity
				retrieve ((int)x.testJSON.id)
				where x.id = 1
			");
			
			foreach($rs as $y) {
				var_dump(gettype($y["x.testJSON.id"]));
			}
			
			$posts = $this->em()->findBy(PostEntity::class, ['published' => true]);
			
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
			$post = $this->em()->find(PostEntity::class, $id);
			
			if (!$post) {
				return $this->notFound('Post does not exist.');
			}
			
			return $this->render("blog/show.tpl", [
				'post' => $post
			]);
		}
	}