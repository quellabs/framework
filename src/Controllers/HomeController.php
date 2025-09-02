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
			$rs = $this->em()->executeQuery("
				range of c is PostEntity
				range of d is PostAnotherEntity via d.id=c.id
				range of e is PostAnotherEntity via d.id=c.id
				retrieve (SUM(e.id + d.id))
			");
			
			/*
			
			$rs = $this->em()->executeQuery("
				range of c is PostEntity
				range of d is PostAnotherEntity via d.id=c.id
				retrieve (SUM(d.id + d.id WHERE d.id=c.id AND d.title = 'hello'))
			");
			*/
			
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