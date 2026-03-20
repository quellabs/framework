<?php
	
	namespace Quellabs\Payments\PayNL;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class PayNLController {
		
		/**
		 * Pay.nl driver instance used to resolve payment state.
		 * @var Driver
		 */
		private Driver $paynl;
		
		/**
		 * Signal emitted after every payment state resolution, carrying the PaymentState.
		 * Listeners (e.g. order management services) subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructs the controller and wires up the payment_exchange signal.
		 *
		 * @param Driver $paynl Pay.nl driver with active configuration already applied
		 */
		public function __construct(Driver $paynl) {
			// Store the driver for use in both handler methods.
			$this->paynl = $paynl;
			
			// Create the signal that both handlers emit after resolving payment state.
			// Listeners attach to this signal to act on payment outcomes without
			// this controller needing to know anything about order management.
			$this->signal = new Signal('payment_exchange');
		}
		
		/**
		 * Handles the Pay.nl return URL — called when the hosted payment page redirects
		 * the shopper back after completing (or abandoning) a payment.
		 *
		 * Pay.nl appends the following parameters to returnUrl:
		 *   ?id={uuid}&orderId={legacyOrderId}
		 *
		 * The 'id' parameter is the order UUID — the stable identifier used for all API calls.
		 * The 'orderId' is the legacy human-readable reference (e.g. "51007856048X14b0");
		 * it is included for display purposes only and not used for API calls.
		 *
		 * Race condition: the shopper may return before Pay.nl has finished processing the
		 * payment (particularly for async methods). If the status is still PENDING (code 20–90),
		 * the final state will arrive via the exchange URL notification. We redirect to the
		 * success page in that case — the order exists and the shopper should see a
		 * "payment pending" confirmation, not an error.
		 *
		 * @Route("paynl::return_url", fallback="/payment/return/paynl", methods={"GET"})
		 * @see https://developer.pay.nl/docs/return-the-payer
		 * @param Request $request Incoming HTTP request from the shopper's browser
		 * @return Response Redirect to success or cancel page, or 400/502 on failure
		 */
		public function handleReturn(Request $request): Response {
			// Pay.nl appends ?id={uuid} to the returnUrl.
			// This UUID is the order's stable identifier — not the legacy orderId.
			$transactionId = $request->query->get('id');
			
			// Reject requests that arrive without the mandatory order identifier.
			// This can happen if the URL is visited directly or misconfigured.
			if (empty($transactionId)) {
				return new JsonResponse("Missing parameter 'id'", 400);
			}
			
			try {
				// Fetch the authoritative payment state from the Pay.nl Order:Status API.
				// The action hint is passed through for informational purposes only —
				// exchange() always calls the API regardless of the action value.
				$response = $this->paynl->exchange($transactionId, ['action' => 'return']);
				
				// Broadcast the resolved state to any registered listeners.
				// Listeners handle order updates, emails, inventory, etc.
				$this->signal->emit($response);
				
				// Read the redirect URLs from the active configuration.
				$config = $this->paynl->getConfig();
				
				// Route the shopper based on the resolved payment state.
				// Cancelled payments go to the cancel URL so the shopper can retry.
				// All other states — including Pending — land on the success/thank-you
				// page so the shopper sees a meaningful confirmation rather than an error.
				if ($response->state === PaymentStatus::Canceled) {
					$redirectUrl = $config['cancel_return_url'];
				} else {
					$redirectUrl = $config['return_url'];
				}
				
				return new RedirectResponse($redirectUrl);
			} catch (PaymentExchangeException $exception) {
				// The API call to Pay.nl failed — return a 502 so upstream monitoring
				// can detect the failure without redirecting the shopper to a broken page.
				return new JsonResponse($exception->getMessage() . ' (' . $exception->getErrorId() . ')', 502);
			}
		}
		
		/**
		 * Handles Pay.nl exchange notifications — server-to-server callbacks POSTed by
		 * Pay.nl when a payment status changes (paid, cancelled, refunded, etc.).
		 *
		 * Pay.nl exchange payload (application/x-www-form-urlencoded):
		 *   action=new_ppt&order_id={uuid}&orderId={legacyId}&...
		 *
		 * The 'order_id' field contains the order UUID. The 'action' field describes the
		 * event type (e.g. 'new_ppt' for new payment, 'paid', 'cancel', 'refund:add',
		 * 'refund:received'). Neither field carries the authoritative payment status —
		 * we always fetch the current state from the API.
		 *
		 * Pay.nl expects a response starting with "TRUE|" for success, or any other body
		 * for failure (which triggers a retry). We return "TRUE|" even if the exchange()
		 * call fails, because the alternative (returning an error) causes infinite retries
		 * on transient API failures. We log errors instead.
		 *
		 * @Route("paynl::exchange_url", fallback="/webhooks/paynl", methods={"POST"})
		 * @see https://developer.pay.nl/docs/handling-the-exchange-calls
		 * @param Request $request Incoming HTTP request from the Pay.nl platform
		 * @return Response "TRUE|" on success or acknowledgement; plain error text on bad input
		 */
		public function handleExchange(Request $request): Response {
			// Pay.nl POSTs form-encoded data with 'order_id' as the primary order UUID field.
			$transactionId = $request->request->get('order_id');
			
			// Some Pay.nl API versions use 'orderId' (camelCase) instead of 'order_id'.
			// Fall back to the camelCase variant for compatibility.
			if (empty($transactionId)) {
				$transactionId = $request->request->get('orderId');
			}
			
			// As a final fallback, check the query string — some exchange URL configurations
			// append the order_id as a query parameter directly on the exchange URL itself.
			if (empty($transactionId)) {
				$transactionId = $request->query->get('order_id');
			}
			
			// A request with no order ID in any location is malformed and cannot be
			// processed. Return a plain-text error — not TRUE| — so the issue is visible
			// in Pay.nl's exchange log without triggering the retry scheme.
			if (empty($transactionId)) {
				return new Response("Missing parameter 'order_id'", 400, ['Content-Type' => 'text/plain']);
			}
			
			try {
				// Read the action field from the POST body; it describes the event type
				// (e.g. 'paid', 'cancel', 'refund:add') but does not carry the status itself.
				// It is forwarded to exchange() as informational context only.
				$action = $request->request->get('action', 'exchange');
				
				// Fetch the authoritative payment state from the Pay.nl Order:Status API.
				$response = $this->paynl->exchange($transactionId, ['action' => $action]);
				
				// Broadcast the resolved state to any registered listeners.
				$this->signal->emit($response);
			} catch (PaymentExchangeException $exception) {
				// Log the failure but still return TRUE| below.
				// Returning a non-TRUE| response would cause Pay.nl to retry indefinitely,
				// flooding the exchange log for what may be a transient API error.
				// The return URL flow provides a secondary opportunity to resolve state.
				error_log('Pay.nl exchange failed: ' . $exception->getMessage() . ' (' . $exception->getErrorId() . ')');
			}
			
			// Pay.nl requires a response starting with "TRUE|" to acknowledge the notification.
			// Any other response body triggers a retry according to the Pay.nl retry scheme.
			return new Response('TRUE|', 200, ['Content-Type' => 'text/plain']);
		}
	}