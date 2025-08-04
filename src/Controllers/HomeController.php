<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\RateLimiting\RateLimitAspect;
	use Quellabs\Contracts\Cache\CacheInterface;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(): Response {
			return $this->render("home/index3.tpl");
		}
		
		/**
		 * @Route("/posts/{id}", methods={"GET", "POST"})
		 * @return Response
		 */
		public function xyz(int $id): Response {
			return $this->json([
				'id'      => 10,
				'title'   => 'test',
				'content' => 'test'
			]);
		}
		
		/**
		 * @Route("/hello/{name}")
		 * @param string $name
		 * @return Response
		 */
		public function hello(string $name): Response {
			return new Response("Hello, " . $name);
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