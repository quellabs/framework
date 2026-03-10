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
			return $this->render('test', [
				'title' => 'Hello',
				'items' => [
					'one', 'two', 'three'
				]
			]);
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