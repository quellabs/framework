<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Translation\TranslationAspect;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\PaymentRouter;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
	
		/**
		 * @InterceptWith(TranslationAspect::class)
		 * @Route("/")
		 * @return Response
		 */
		public function index(PaymentProviderInterface $paymentRouter): Response {
			$request = new PaymentRequest(
				"paypal_express_checkout",
				10,
				"EUR",
				"test",
				"hallo",
			);

			$response = $paymentRouter->initiate($request);
			return new Response("Hello from routes file");
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