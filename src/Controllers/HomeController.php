<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\PaymentRouter;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends SecureController {
	
		/**
		 * @Route("/")
		 * @return Response
		 */
		public function index(PaymentRouter $paymentRouter): Response {
			$request = new PaymentRequest(
				"mollie_ideal",
				1.0,
				"EUR",
				"test",
				"hallo",
			);
			
			return $this->json($paymentRouter->initiate($request)->response);

			/*
			return new JsonResponse($mollie->initiate();
			*/
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