<?php
	
	namespace App\Controllers;

	use App\Entities\PostEntity;
	use App\Models\Post;
	use Illuminate\Database\ConnectionInterface;
	use Psr\Log\LoggerInterface;
	use Quellabs\SignalHub\Slot;
	use App\Entities\TestEntity;
	use App\Entities\UserEntity;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\Canvas\Tracking\TrackingParamsAspect;
	
	class BlogController extends BaseController {
		
		/**
		 * @Route("/posts/")
		 * @WithContext(parameter="engine", context="blade")
		 * @InterceptWith(TrackingParamsAspect::class)
		 * @return Response
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws TemplateRenderException
		 * @throws OrmException
		 */
		public function index(): Response {
			$posts = $this->em()->findBy(PostEntity::class, ['published' => true]);

			return $this->render("blog/index.tpl", [
				'posts' => $posts
			]);
		}
		
		/**
		 * @Route("/posts/{id:int}")
		 * @param PostEntity|null $id
		 * @return Response
		 * @throws TemplateRenderException
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		public function show(?PostEntity $id): Response {
			if (!$id) {
				return $this->notFound('Post does not exist.');
			}

			return $this->render("blog/show.tpl", [
				'post' => $id
			]);
		}

		/**
		 * Reads published posts straight through Eloquent's query builder,
		 * bypassing ObjectQuel entirely, to verify the canvas-eloquent
		 * integration against the same 'posts' table.
		 * @Route("/posts/eloquent-test")
		 * @param ConnectionInterface $db
		 * @return Response
		 */
		public function eloquentTest(ConnectionInterface $db): Response {
			$posts = $db
				->table('posts')
				->where('published', true)
				->orderBy('id')
				->get(['id', 'title', 'published'])
				->toArray();

			return $this->json(['posts' => $posts]);
		}

		/**
		 * Queries a plain Eloquent model with no ConnectionInterface involved
		 * anywhere in this method - not as a parameter, not resolved manually.
		 * Only proves the eager-boot claim if this succeeds: canvas-eloquent's
		 * ServiceProvider::setConfig() must have already booted the Capsule and
		 * registered Eloquent's static connection resolver during Kernel/DI
		 * container construction, before this controller was even instantiated.
		 * @Route("/posts/eloquent-model-test")
		 * @return Response
		 */
		public function eloquentModelTest(): Response {
			$posts = Post::where('published', true)
				->orderBy('id')
				->get(['id', 'title', 'published'])
				->toArray();

			return $this->json(['posts' => $posts]);
		}
	}