<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use App\Enums\TestEnum;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends SecureController {
		
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(): Response {
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