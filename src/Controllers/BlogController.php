<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Contracts\Cache\CacheInterface;
	use Symfony\Component\HttpFoundation\Response;
	
	class BlogController extends BaseController {
		
		/**
		 * @Route("/posts/")
		 * @return Response
		 */
		public function index(): Response {
			
			$x = $this->em()->executeQuery("
				range of x is PostEntity
				range of y is json_source('f:\\test.json', '$.rows')
				retrieve(x.id, y.id, x.title, y.test)
				where y.id=x.id
			");
			
			print_r($x->fetchAll());
			
			//$posts = $this->em()->findBy(PostEntity::class, ['published' => true]);
			
			return $this->render("blog/index.tpl", [
				'posts' => []
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