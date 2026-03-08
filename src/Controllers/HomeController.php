<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use App\Enums\TestEnum;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Canvas\Validation\Rules\Date;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends SecureController {
	
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(Request $request): Response {
			$p = new PostEntity();
			$p->setTitle('x');
			$p->setContent('x');
			$p->setPublished(true);
			$p->setCreatedAt(new \DateTime());
			$p->setTestEnum(TestEnum::DELIVERED);
			
			$this->em()->persist($p);
			$this->em()->flush();
			
			
			
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