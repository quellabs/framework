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
			return $this->render('home_plates', [
				'title' => 'Test Page',
				'name'  => 'Floris',
				'items' => ['Foo', 'Bar', 'Baz'],
			]);
		}
		
		/**
		 * @Route("routes::test")
		 * @return Response
		 */
		public function hello(): Response {
			return new Response("Hello from routes file");
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