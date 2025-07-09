<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Canvas\Sanitization\SanitizeAspect;
	use Quellabs\Canvas\Cache\CacheAspect;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(): Response {
			return new Response("<h1>Welcome to Canvas Blog!</h1>");
		}
		
		/**
		 * @Route("/hello/{name}")
		 * @param string $name
		 * @return Response
		 */
		public function hello(string $name): Response {
			return  new Response("Hello, " . $name);
		}
		
		/**
		 * @Route("/user/{id:int}")
		 * @param int $id
		 * @return Response
		 */
		public function user(int $id): Response {
			return  new Response("Hello, " . $id);
		}
	}