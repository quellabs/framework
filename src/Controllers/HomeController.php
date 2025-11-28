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
			$entity = new PostEntity();
			$entity->setTitle("hallo 2");
			$entity->setContent("hallo 2");
			$entity->setPublished(true);
			$entity->setTestEnum(TestEnum::CANCELLED);
			$entity->setCreatedAt(new \DateTime());
			
			$this->em()->persist($entity);
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