<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\CanvasObjectQuel\Annotations\ResolveEntity;
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
				retrieve ((float)x.testJSON.id)
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
		 * @param PostEntity|null $entity
		 * @return Response
		 * @throws \Quellabs\Contracts\Templates\TemplateRenderException
		 * @throws \Quellabs\ObjectQuel\Exception\EntityResolutionException
		 * @throws \Quellabs\ObjectQuel\Exception\QuelException
		 */
		public function show(?PostEntity $entity): Response {
			if (!$entity) {
				return $this->notFound('Post does not exist.');
			}
			
			return $this->render("blog/show.tpl", [
				'post' => $entity
			]);
		}
	}