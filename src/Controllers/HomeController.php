<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(): Response {
			$this->em()->executeQuery("
				range of x is PostEntity
				range of y is PostEntity via y.id=x.id
				retrieve (x.id) WHERE ANY(y.id)
			");
			
			return $this->render("home/index3.tpl");
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