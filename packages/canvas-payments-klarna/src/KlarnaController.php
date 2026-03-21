<?php
	
	namespace Quellabs\Payments\Klarna;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	/**
	 * Handles HTTP callbacks from Klarna for the Hosted Payment Page (HPP) integration.
	 *
	 * Two entry points:
	 *
	 * 1. Return URL (GET)  — consumer is redirected back by Klarna after completing or
	 *    abandoning a payment. The URL carries ?order_id= (on success) or nothing (on
	 *    cancel/failure). The authoritative payment state is fetched via the Order
	 *    Management API and broadcast as a PaymentState signal.
	 *
	 * 2. HPP Status Callback (POST) — optional server-to-server notification from Klarna
	 *    when the HPP session status changes. Configure the callback URL in the HPP session
	 *    creation payload under merchant_urls.notification (if supported by your contract).
	 *    This provides a reliable delivery path independent of the consumer's browser.
	 *
	 * Signal:
	 *   Both entry points emit the 'payment_exchange' signal carrying a PaymentState.
	 *   Listeners (e.g. order management services) subscribe to act on payment outcomes.
	 *
	 * Note on signature verification:
	 *   Klarna does not sign the return URL query string (unlike Rabo Smart Pay).
	 *   The order_id from the query string is therefore only used as a lookup key —
	 *   the authoritative state always comes from the Order Management API.
	 */
	class KlarnaController {
		
		/**
		 * Klarna driver instance used to resolve payment state.
		 * @var Driver
		 */
		private Driver $klarna;
		
		/**
		 * Signal emitted after every payment state resolution, carrying the PaymentState.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructs the controller and wires up the payment_exchange signal.
		 *
		 * @param Driver $klarna Klarna driver with active configuration already applied
		 */
		public function __construct(Driver $klarna) {
			$this->klarna = $klarna;
			$this->signal = new Signal('payment_exchange');
		}
		
		/**
		 * Handles the Klarna HPP return URL — called when Klarna redirects the consumer
		 * back to the merchant site after payment completion, cancellation, or failure.
		 *
		 * The success URL carries:
		 *   ?order_id=<klarna_order_id>&authorization_token=<token>
		 * (Populated by the {{order_id}} and {{authorization_token}} HPP placeholders
		 * set in KlarnaController::initiate() when creating the HPP session.)
		 *
		 * The cancel/failure/error URLs carry no meaningful query parameters.
		 *
		 * Klarna does NOT sign the return URL — the order_id is used only as a lookup
		 * key. The authoritative state is fetched from the Order Management API.
		 *
		 * @Route("klarna::return_url", fallback="/payment/return/klarna", methods={"GET"})
		 * @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/
		 * @param Request $request Incoming HTTP request from the consumer's browser
		 * @return Response Redirect to success or cancel page, or error response
		 */
		public function handleReturn(Request $request): Response {
			$config = $this->klarna->getConfig();
			
			// Extract the Klarna order_id and authorization_token from the query string.
			// These are present only on the success redirect; cancel/error carry nothing.
			$orderId            = $request->query->get('order_id', '');
			$authorizationToken = $request->query->get('authorization_token', '');
			
			// If no order_id is present, the consumer cancelled or encountered an error.
			// Route to the cancel page without attempting an exchange — there is no
			// authoritative order to look up yet (Klarna creates the order only on
			// successful authorisation).
			if (empty($orderId)) {
				return new RedirectResponse($config['cancel_return_url']);
			}
			
			// Fetch authoritative payment state from the Order Management API.
			// This is the only reliable source of truth — the query string alone
			// is not signed and should never be used to update order status.
			try {
				$paymentState = $this->klarna->exchange($orderId);
				$this->signal->emit($paymentState);
			} catch (PaymentExchangeException $e) {
				// Log and fall through to the success redirect. The consumer has
				// completed payment on Klarna's side — an exchange failure here is
				// a transient API issue, not a payment failure. The application
				// should reconcile via the HPP status callback or manual lookup.
				error_log('Klarna exchange error on return URL: ' . $e->getMessage() . ' (' . $e->getErrorId() . ')');
			}
			
			// Route based on the presence of an order_id. If we got this far, the
			// consumer completed the Klarna flow — direct them to the success page.
			// The signal listener will handle any order state updates asynchronously.
			return new RedirectResponse($config['return_url']);
		}
		
		/**
		 * Handles Klarna HPP status callback — an optional server-to-server POST that
		 * Klarna sends when the HPP session status changes (COMPLETED, DISABLED, ERROR).
		 *
		 * This endpoint provides reliable delivery independent of the consumer's browser
		 * redirect, which may fail due to navigation, closed tab, or network issues.
		 *
		 * Callback payload (application/json):
		 *   {
		 *     "session_id": "<hpp_session_id>",
		 *     "status":     "COMPLETED" | "DISABLED" | "ERROR" | "EXPIRED",
		 *     "order_id":   "<klarna_order_id>"   // present when status is COMPLETED
		 *   }
		 *
		 * Klarna does NOT sign this callback payload. Validate the order_id by fetching
		 * the authoritative state from the Order Management API before trusting it.
		 *
		 * Klarna expects HTTP 200 in response. Retries are not guaranteed — log errors
		 * and use manual reconciliation if the callback is not received.
		 *
		 * @Route("klarna::webhook_url", fallback="/webhooks/klarna", methods={"POST"})
		 * @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/api-documentation/status-callbacks/
		 * @param Request $request Incoming HTTP request from Klarna
		 * @return Response HTTP 200 on success or acknowledgment; 400 on bad input
		 */
		public function handleWebhook(Request $request): Response {
			// Klarna sends JSON; parse the body regardless of Content-Type header.
			$body = json_decode($request->getContent(), true);
			
			// Validate the parsed JSON
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
				return new Response('Invalid JSON body', 400, ['Content-Type' => 'text/plain']);
			}
			
			$status  = strtoupper($body['status'] ?? '');
			$orderId = $body['order_id'] ?? '';
			
			// Only COMPLETED sessions carry an order_id and are worth acting on.
			// DISABLED, EXPIRED, and ERROR are terminal without an order to exchange.
			if ($status !== 'COMPLETED' || empty($orderId)) {
				// Acknowledge the notification so Klarna doesn't retry.
				return new Response('', 200);
			}
			
			// Fetch authoritative payment state from the Order Management API.
			try {
				$paymentState = $this->klarna->exchange($orderId);
				$this->signal->emit($paymentState);
			} catch (PaymentExchangeException $e) {
				error_log('Klarna webhook exchange error: ' . $e->getMessage() . ' (' . $e->getErrorId() . ')');
				// Still return 200 — retrying will not fix an exchange failure.
			}
			
			return new Response('', 200);
		}
	}