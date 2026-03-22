<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Translation\TranslationAspect;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
	
		/**
		 * @InterceptWith(TranslationAspect::class)
		 * @Route("/")
		 * @return Response
		 */
		public function index(TemplateEngineInterface $engine): Response {
			return new Response("Hello from routes file");
		}
		
		/**ho
		 * @Route("routes::test")
		 * @return Response
		 */
		public function hello(): Response {
			return new Response("Hello from routes file");
		}
		
		/**
		 * @Route("/user/{path:**}/hallo")
		 * @param string $path
		 * @return Response
		 */
		public function user(string $path): Response {
			return new Response("<h1>Hello, " . $path . "</h1>");
		}
	}