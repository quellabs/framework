<?php
	
	namespace App\Controllers;
	
	use App\Entities\PostEntity;
	use App\Enums\TestEnum;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Canvas\Validation\Rules\Date;
	use Quellabs\Contracts\Payment\PaymentRequest;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\Payments\Mollie\Mollie;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends SecureController {
	
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(Mollie $mollie): Response {
			return new JsonResponse($mollie->initiate(new PaymentRequest(
				"mollie",
				1.0,
				"EUR",
				"test",
				"hallo",
			)));
		}
		
		/**ho
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