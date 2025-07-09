<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(): Response {

			return new JsonResponse($map);
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