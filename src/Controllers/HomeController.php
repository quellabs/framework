<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use App\Enums\TestEnum;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(): Response {
			$tmp = $this->em()->executeQuery("
				range of x is (
		            range of y is PostEntity
	                retrieve(y.id)
				)
				range of z is PostEntity
				retrieve(z) where z.id=x.id
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