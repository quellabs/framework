<?php
	
	namespace Quellabs\Payments\MultiSafepay;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class MultiSafepayController {
		
		/**
		 * @var Driver MultiSafepay driver
		 */
		private Driver $msp;
		
		/**
		 * Emitted after a payment state change, carrying the updated PaymentState.
		 * Listeners (e.g. order management) should subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $msp
		 */
		public function __construct(Driver $msp) {
			$this->msp    = $msp;
			$this->signal = new Signal('payment_exchange');
		}
		
		/**
		 * Handles the MultiSafepay return URL — called when the hosted payment page redirects
		 * the shopper back after completing (or abandoning) a payment.
		 *
		 * MSP appends ?transactionid=<order_id> to the redirect_url configured on the order.
		 * The parameter name is 'transactionid' (lowercase, no underscore) — this is MSP's term
		 * for your order_id / reference, not an MSP-internal identifier.
		 *
		 * Unlike Adyen, MSP does not append a resultCode or redirectResult. The payment status
		 * must always be fetched by calling GET /orders/{order_id} — exchange() handles this.
		 *
		 * Note: the shopper may return before MSP has finished processing the payment (race
		 * condition on async methods). If the status is 'initialized', the order has not yet
		 * been settled — the final state arrives via the webhook notification_url.
		 *
		 * @Route("msp::return_url", fallback="/payment/return/multisafepay", methods={"GET"})
		 * @see https://docs.multisafepay.com/docs/redirect-url
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			// MSP appends ?transactionid= (their naming, not ours) to the redirect_url.
			$transactionId = $request->query->get('transactionid');
			
			if (empty($transactionId)) {
				return new JsonResponse("Missing parameter 'transactionid'", 400);
			}
			
			try {
				// Fetch the authoritative payment state from the MSP API.
				// action='return' is informational — exchange() always calls the API.
				$response = $this->msp->exchange($transactionId, ['action' => 'return']);
				
				// Notify listeners (e.g. order management) of the updated payment state.
				$this->signal->emit($response);
				
				$config = $this->msp->getConfig();
				
				// Route the shopper to the cancel URL if they cancelled, otherwise to the
				// success page. Note: 'initialized' / 'pending' lands on the success page
				// intentionally — the order exists and the shopper should be shown a
				// "payment pending" confirmation, not an error.
				if ($response->state === PaymentStatus::Canceled) {
					$redirectUrl = $config['cancel_return_url'];
				} else {
					$redirectUrl = $config['return_url'];
				}
				
				return new RedirectResponse($redirectUrl);
			} catch (PaymentExchangeException $exception) {
				return new JsonResponse($exception->getMessage() . ' (' . $exception->getErrorId() . ')', 502);
			}
		}
		
		/**
		 * Handles MultiSafepay webhook notifications — asynchronous server-to-server callbacks
		 * POSTed by MSP after a payment status change (completion, refund, chargeback, etc.).
		 *
		 * MSP webhook payload:
		 *   POST body (application/x-www-form-urlencoded): transactionid=<order_id>&timestamp=<unix>
		 *
		 * Important: the POST body contains only the order_id and a timestamp — no status, no
		 * amount, no signature. The authoritative state must always be fetched from the API.
		 *
		 * There is no HMAC mechanism for MSP webhooks. Authenticity is validated implicitly:
		 * exchange() calls GET /orders/{order_id} using the configured API key, so a forged
		 * notification with a valid order_id will still only return the real MSP-side status.
		 *
		 * MSP requires an HTTP 200 response. Any non-200 response causes MSP to retry.
		 *
		 * @Route("msp::notification_url", fallback="/webhooks/multisafepay", methods={"POST"})
		 * @see https://docs.multisafepay.com/docs/notification-url
		 * @param Request $request
		 * @return Response
		 */
		public function handleWebhook(Request $request): Response {
			// MSP POSTs form-encoded data; 'transactionid' is the order_id from order creation.
			$transactionId = $request->request->get('transactionid');
			
			if (empty($transactionId)) {
				// MSP may also send the transactionid as a query parameter on the notification_url
				// when configured via the Customer Portal. Check both locations.
				$transactionId = $request->query->get('transactionid');
			}
			
			if (empty($transactionId)) {
				return new JsonResponse("Missing parameter 'transactionid'", 400);
			}
			
			try {
				// Fetch the authoritative payment state from the MSP API.
				$response = $this->msp->exchange($transactionId, ['action' => 'webhook']);
				
				// Notify listeners (e.g. order management) of the updated payment state.
				$this->signal->emit($response);
			} catch (PaymentExchangeException $exception) {
				// Log the failure. Unlike Adyen, MSP does not require a specific literal response
				// body — but we must return HTTP 200 to prevent retries.
				// Replace error_log with your application's logger.
				error_log('MultiSafepay webhook exchange failed: ' . $exception->getMessage() . ' (' . $exception->getErrorId() . ')');
			}
			
			// MSP requires HTTP 200. The response body is not checked.
			return new Response('OK', 200, ['Content-Type' => 'text/plain']);
		}
	}