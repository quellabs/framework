<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\RateLimiting\RateLimitAspect;
	use Quellabs\Canvas\Cache\Contracts\CacheInterface;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		/**
		 * @Route("/")
		 * @InterceptWith(RateLimitAspect::class)
		 * @return Response
		 */
		public function index(CacheInterface $cache): Response {
			return new Response("<h1>Welcome to Canvas Blog!</h1>");
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
			return new Response("Hello, " . $path);
		}
	}