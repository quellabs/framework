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
			$tmp = $this->em()->find(PostEntity::class, 5);
			$tmp->setCreatedAt(new \DateTime());
			
			$this->em()->persist($tmp);
			$this->em()->flush();
			
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