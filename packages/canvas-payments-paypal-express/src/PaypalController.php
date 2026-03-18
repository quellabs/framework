<?php
	
	namespace Quellabs\Payments\Paypal;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Configuration\ConfigLoader;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class PaypalController {
		
		private Driver $paypal;
		
		/**
		 * Emitted after a payment state change, carrying the updated PaymentState.
		 * Listeners (e.g. order management) should subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $paypal
		 */
		public function __construct(Driver $paypal) {
			$this->paypal = $paypal;
			$this->signal = new Signal("payment_exchange");
		}
		
		/**
		 * Handles the PayPal return URL — called when the buyer completes or cancels at PayPal.
		 * PayPal appends the checkout token as ?token=EC-XXXXXXXXX and the action as ?action=return|cancel.
		 * Captures the payment via DoExpressCheckoutPayment and emits the resulting PaymentState.
		 * @Route("paypal::return_url", fallback="/payment/return/paypal", methods={"GET"})
		 * @see https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECInstantUpdateAPI/
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			// PayPal appends the token to the return URL as ?token=EC-XXXXXXXXX
			$token = $request->query->get('token');
			
			if (empty($token)) {
				return new JsonResponse("Missing parameter 'token'", 400);
			}
			
			try {
				// Call the exchange method in the driver to convert raw status to PaymentState
				$action = $request->query->get('action');
				$response = $this->paypal->exchange($token, [
					'action' => $action
				]);
				
				// Error 10486: buyer's funding source was insufficient — redirect them back to PayPal
				// to choose a different payment method. No signal emitted — this is not a payment outcome.
				if ($response->state === PaymentStatus::Redirect) {
					return new RedirectResponse($response->metadata['redirectUrl']);
				}

				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				// Redirect the buyer to the configured post-payment return page
				$config = $this->paypal->getConfig();
				
				if ($response->state === PaymentStatus::Canceled) {
					$redirectUrl = $config["cancel_return_url"];
				} else {
					$redirectUrl = $config["return_url"];
				}
				
				return new RedirectResponse($redirectUrl);
			} catch (PaymentExchangeException $exception) {
				return new JsonResponse($exception->getMessage() . " (" . $exception->getErrorId() . ")", 502);
			}
		}
		
		/**
		 * Handles PayPal IPN (Instant Payment Notification) — asynchronous server-to-server
		 * payment status updates POSTed by PayPal after a transaction completes or changes state.
		 * IPN is the authoritative source of payment state and may arrive before or after the
		 * buyer returns to the return URL, so it must be handled independently.
		 * @Route("paypal::ipn_url", fallback="/webhooks/paypal", methods={"POST"})
		 * @see https://developer.paypal.com/docs/api-basics/notifications/ipn/
		 * @param Request $request
		 * @return Response
		 */
		public function handleIpn(Request $request): Response {
			// Fetch all post data
			$data = $request->request->all();
			
			// Verify the IPN message is genuinely from PayPal by echoing it back for validation.
			// PayPal responds with either "VERIFIED" or "INVALID" — never process unverified messages.
			$verification = $this->paypal->verifyIpnMessage($data);
			
			// If that failed, return an error message
			if ($verification["request"]["result"] == 0 || $verification["response"] !== "VERIFIED") {
				return new JsonResponse("IPN verification failed", 400);
			}
			
			// The checkout token is included in the IPN payload and identifies the payment session
			$token = $data['token'] ?? null;
			
			// If no token, this is an invalid call
			if (empty($token)) {
				return new JsonResponse("Missing parameter 'token'", 400);
			}
			
			// txn_id is PayPal's payment transaction ID — required for refund state retrieval
			$paymentTransactionId = $data['txn_id'] ?? null;
			
			try {
				// Call Driver's exchange method to convert raw data to PaymentState
				$response = $this->paypal->exchange($token, [
					'action'               => 'ipn',
					'paymentTransactionId' => $paymentTransactionId,
				]);
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				// PayPal considers any 2xx response a successful IPN delivery.
				// Failure to respond with 2xx will cause PayPal to retry the notification.
				return new JsonResponse("OK");
			} catch (PaymentExchangeException $exception) {
				return new JsonResponse($exception->getMessage() . " (" . $exception->getErrorId() . ")", 502);
			}
		}
	}