<?php
	
	namespace Quellabs\Payments\Mollie;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class MollieController {
		
		private Kernel $kernel;
		private Driver $mollie;
		
		/**
		 * This signal emits
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Kernel $kernel
		 * @param Driver $mollie
		 */
		public function __construct(Kernel $kernel, Driver $mollie) {
			$this->kernel = $kernel;
			$this->mollie = $mollie;
			$this->signal = new Signal("payment_exchange");
		}
		
		/**
		 * @Route("mollie::webhookUrl", fallback="/webhooks/mollie", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function exchange(Request $request): Response {
			// Mollie POSTs a single 'id' parameter — reject anything that doesn't have it
			if (!$request->request->has('id')) {
				return new JsonResponse("Missing parameter 'id'", 204);
			}
			
			// Fetch the current payment state from Mollie using the transaction ID
			try {
				$response = $this->mollie->exchange($request->request->get("id"));
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				// Mollie considers any 2xx response a successful delivery
				return new JsonResponse("OK");
			} catch (PaymentExchangeException $exception) {
				return new JsonResponse($exception->getMessage() . " (" . $exception->getErrorId(). ")", 502);
			}
		}
		
		/**
		 * @Route("mollie::redirectUrl", fallback="/payment/return/mollie", methods={"GET"})
		 * @url https://docs.mollie.com/payments/webhooks
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			$config = $this->kernel->loadConfigFile('mollie');
			return new RedirectResponse($config->get("return_url"));
		}
		
		/**
		 * @Route("mollie::cancelUrl", fallback="/payment/cancel/mollie", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 */
		public function handleCancel(Request $request): Response {
			$config = $this->kernel->loadConfigFile('mollie');
			return new RedirectResponse($config->get("cancel_return_url"));
		}
	}