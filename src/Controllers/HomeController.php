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
				range of d is PostEntity via d.title=c.title
				retrieve (SUM(d.id)) WHERE c.id=10
			");
			
			// SELECT COALESCE(SUM(d.id), 0) as `SUM(d.id)` FROM `posts` as `c` LEFT JOIN `posts` as `d` ON d.title = c.title
			
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