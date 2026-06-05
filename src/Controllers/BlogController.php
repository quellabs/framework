<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Psr\Log\LoggerInterface;
	use Quellabs\SignalHub\Slot;
	use App\Entities\TestEntity;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PhpClassParser;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\Canvas\Tracking\TrackingParamsAspect;
	
	class BlogController extends BaseController {
		
		/**
		 * @Route("/posts/")
		 * @WithContext(parameter="engine", context="blade")
		 * @InterceptWith(TrackingParamsAspect ::class)
		 * @param TemplateEngineInterface $engine
		 * @return Response
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws TemplateRenderException
		 */
		public function index(SignalHub $hub, TemplateEngineInterface $engine): Response {
			
			PhpClassParser::parseClassContent(file_get_contents(__FILE__));
			
			$posts = $this->em()->find(TestEntity::class, 1);
			
			return $this->render("blog/index.tpl", [
				'posts' => []
			]);
		}
		
		/**
		 * @Route("/posts/{id:int}")
		 * @param PostEntity|null $entity
		 * @return Response
		 * @throws TemplateRenderException
		 * @throws EntityResolutionException
		 * @throws QuelException
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