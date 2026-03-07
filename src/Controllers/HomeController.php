<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\SecureController;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends SecureController {
		
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(Request $request): Response {
			$this->em()->executeQuery("
				range of p is App\Entities\PostEntity
				retrieve (p, score=search_score(p.content, :term))
				where p.published = true and search(p.content, :term)
				sort by score desc
			", [
				'term' => "hello",
			]);
			
			return $this->render("home/index3.tpl");
		}
		
		/**
		 * @Route("/hello/{name:int}")
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