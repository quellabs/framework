<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\CacheContext;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Cache\CacheAspect;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Contracts\Cache\CacheInterface;
	use Symfony\Component\HttpFoundation\Response;
	
	function fixDate(string $date): string {
		$parts = explode('/', $date);
		
		if (strlen($parts[2]) == 2) {
			$parts[2] = '19' . $parts[2];
		}
		
		return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
	}
	
	class BlogController extends BaseController {

		
		/**
		 * @Route("/postsx/")
		 * @CacheContext(lockTimeout=10)
		 * @return Response
		 */
		public function index(CacheInterface $cache): Response {
			$posts = $this->em->findBy(PostEntity::class, ['published' => true]);
			
			return $this->render("blog/index.tpl", [
				'posts' => $posts
			]);
		}
		
		/**
		 * @Route("/postsx/{id:int}")
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