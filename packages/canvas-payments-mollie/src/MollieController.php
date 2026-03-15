<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Kernel;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class MollieController {
		
		private Kernel $kernel;
		private Mollie $mollie;
		private Signal $signal;
		
		/**
		 * Constructor
		 * @param Kernel $kernel
		 * @param Mollie $mollie
		 */
		public function __construct(Kernel $kernel, Mollie $mollie) {
			$this->kernel = $kernel;
			$this->mollie = $mollie;
			$this->signal = new Signal("payment_exchange");
		}
		
		/**
		 * @Route("/webhooks/mollie", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function exchange(Request $request): Response {
			// Mollie POSTs a single 'id' parameter — reject anything that doesn't have it
			if (!$request->request->has('id')) {
				return new JsonResponse("Missing parameter 'id'", 204);
			}
			
			// Fetch the current payment state from Mollie using the transaction ID
			$response = $this->mollie->exchange($request->request->get("id"));
			
			// If the exchange failed, return 500 so Mollie will retry the webhook
			if (!$response->success) {
				return new JsonResponse($response->errorMessage, $response->errorId !== 0 ? $response->errorId : 500);
			}
			
			// Notify listeners (e.g. order management) of the updated payment state
			$this->signal->emit($response->response);
			
			// Mollie considers any 2xx response a successful delivery
			return new JsonResponse("OK");
		}
		
		/**
		 * @Route("/payment/return/mollie", methods={"GET"})
		 * @url https://docs.mollie.com/payments/webhooks
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			$config = $this->kernel->loadConfigFile('mollie');
			return new RedirectResponse($config->get("returnUrl"));
		}
	}