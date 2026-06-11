<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Loom\Builder\Column;
	use Quellabs\Canvas\Loom\Builder\Resource;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Loom\Loom;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\Canvas\Translation\TranslationAspect;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		const int MSG_SHOW_DELETE = 10000;
		const int MSG_HIDE_DELETE = 10001;
		
		private AnnotationReader $annotationReader;
		
		public function __construct(Container $container, AnnotationReader $annotationReader) {
			parent::__construct($container);
			$this->annotationReader = $annotationReader;
		}
		
		/**
		 * Builds the resource definition, shared between GET and POST.
		 * Rules are defined here once so both rendering and validation use
		 * the same source.
		 * @throws \ReflectionException
		 */
		private function buildDefinition(): array {
			$post = $this->em()->find(PostEntity::class, 1);
			
			$resource = Resource::makeFromEntity($post, $this->annotationReader)
				->wrapInColumns([
					'main'    => Column::make(),
					'sidebar' => Column::make(),
				]);
			
			return $resource->build();
		}
		
		/**
		 * @InterceptWith(TranslationAspect::class)
		 * @WithContext(parameter="engine", context="blade")
		 * @Route("/")
		 * @param TemplateEngineInterface $engine
		 * @return Response
		 * @throws \ReflectionException
		 */
		public function index(TemplateEngineInterface $engine): Response {
			$loom = new Loom();
			
			$definition = $this->buildDefinition();

			$renderedDefinition = $loom->render($definition, [
				'title'   => 'My First Post',
				'slug'    => 'my-first-post',
				'status'  => 'draft',
				'country' => 'nl',
				'region'  => 'nh',
				'city'    => 'ams',
			]);
			
			return $this->buildResponse($renderedDefinition);
		}
		
		/**
		 * @Route("/upload", methods={"POST"})
		 */
		public function upload(Request $request): Response {
			$file = $request->files->get('file');
			
			// Store the file however you like, return the reference
			return new Response(
				json_encode([
					'id'   => uniqid(),
					'name' => $file->getClientOriginalName(),
					'size' => $file->getSize(),
				]),
				200,
				['Content-Type' => 'application/json']
			);
		}
		
		/**
		 * @Route("/save", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function save(Request $request): Response {
			return new Response();
		}
		
		/**
		 * Wraps rendered Loom output in a minimal HTML page.
		 * @param string $body
		 * @return Response
		 */
		private function buildResponse(string $body): Response {
			return new Response("
				<html>
				<head>
				<script src='/wakapac.js'></script>
				<script src='/wakaform.js'></script>
				<script src='/wakajodit.js'></script>
				<script src='/wakasync.js'></script>
				<link rel='stylesheet' type='text/css' href='/loom.css'>
				<link rel='preconnect' href='https://fonts.googleapis.com'>
				<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
				<link href='https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap' rel='stylesheet'>
				<script>
					wakaPAC.use(wakaForm);
					wakaPAC.use(WakaJodit);
					wakaPAC.use(wakaSync);
				</script>
				<style>
				  body {
				    font-family: 'Roboto', sans-serif;
				    font-optical-sizing: auto;
				    font-weight: 400;
				  }
				</style>
				</head>
				<body>
					{$body}
				</body>
				</html>
			");
		}
	}