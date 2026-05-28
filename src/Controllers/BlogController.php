<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Psr\Log\LoggerInterface;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	class BlogController extends BaseController {
		
		/**
		 * @Route("/posts/")
		 * @WithContext(parameter="engine", context="blade")
		 * @return Response
		 */
		public function index(TemplateEngineInterface $engine): Response {
			$rs = $this->em()->executeQuery("
				range of x is PostEntity
				retrieve(
					(datetime)date('2 day') - date('1 day')
				)
			");
			
			foreach($rs as $r) {
				var_dump($r);
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