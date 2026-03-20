<?php
	
	namespace Quellabs\Payments\Buckaroo;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class BuckarooController {
		
		/**
		 * @var Driver Buckaroo driver instance
		 */
		private Driver $buckaroo;
		
		/**
		 * Emitted after a payment state change, carrying the updated PaymentState.
		 * Listeners (e.g. order management) should subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $buckaroo
		 */
		public function __construct(Driver $buckaroo) {
			$this->buckaroo = $buckaroo;
			$this->signal   = new Signal('payment_exchange');
		}
		
		/**
		 * Handles the Buckaroo return URL — called when the hosted payment page redirects
		 * the shopper back after completing (or abandoning) a payment.
		 *
		 * Buckaroo appends the following query parameters to the configured ReturnURL:
		 *   BRQ_TRANSACTIONS   = the Buckaroo transaction key (32-char hex)
		 *   BRQ_INVOICENUMBER  = your Invoice reference from the original request
		 *   BRQ_STATUSCODE     = numeric status code (informational — do NOT trust this alone)
		 *   BRQ_SIGNATURE      = SHA-1 HMAC of the response params (optional verification)
		 *
		 * We always fetch the authoritative state via the API; we do not rely on BRQ_STATUSCODE
		 * in the query string, as it is not signed with HMAC in all configurations.
		 *
		 * Race condition note: the shopper may return before Buckaroo has finished processing
		 * (common for async methods like bank transfers). Status 791 (pending processing) is
		 * normal on return — the final status arrives via the push notification.
		 *
		 * @Route("bkr::return_url", fallback="/payment/return/buckaroo", methods={"GET"})
		 * @see https://docs.buckaroo.io/docs/integration-redirects
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			// BRQ_TRANSACTIONS contains Buckaroo's own transaction key (our transactionId).
			$transactionId = $request->query->get('BRQ_TRANSACTIONS');
			
			// Guard: Buckaroo should always append this, but be defensive.
			if (empty($transactionId)) {
				return new JsonResponse("Missing parameter 'BRQ_TRANSACTIONS'", 400);
			}
			
			try {
				// Fetch the authoritative payment state from the Buckaroo API.
				// action='return' is informational — exchange() always calls the API.
				$state = $this->buckaroo->exchange($transactionId, ['action' => 'return']);
				
				// Notify listeners (e.g. order management) of the updated payment state.
				$this->signal->emit($state);
				
				// Route the shopper based on payment outcome.
				// Canceled: redirect to the cancel URL.
				// Pending or paid: redirect to the success/thankyou page — the application
				// layer should show a "payment pending" message for pending states.
				$config = $this->buckaroo->getConfig();
				
				if ($state->state === PaymentStatus::Canceled) {
					$redirectUrl = $config['return_url_cancel'];
				} elseif ($state->state === PaymentStatus::Failed) {
					// Failed: redirect to the error URL (or fall back to cancel URL)
					$redirectUrl = $config['return_url_error'] ?: $config['return_url_cancel'];
				} else {
					$redirectUrl = $config['return_url'];
				}
				
				return new RedirectResponse($redirectUrl);
			} catch (PaymentExchangeException $exception) {
				return new JsonResponse($exception->getMessage() . ' (' . $exception->getErrorId() . ')', 502);
			}
		}
		
		/**
		 * Handles Buckaroo push notifications — asynchronous server-to-server callbacks
		 * POSTed by Buckaroo after a payment status change (completion, refund, chargeback, etc.).
		 *
		 * Buckaroo push payload (JSON, application/json):
		 * {
		 *   "Transaction": {
		 *     "Key":    "<32-char hex transaction key>",
		 *     "Invoice": "<your invoice reference>",
		 *     "Status": { "Code": { "Code": 190 }, ... },
		 *     ...
		 *   }
		 * }
		 *
		 * Important: although the push body contains a Status.Code, this is not guaranteed to be
		 * up-to-date for all payment methods and lifecycle events. We always call the API to be safe.
		 *
		 * Buckaroo requires an HTTP 200 response to acknowledge receipt. Any non-200 response
		 * causes Buckaroo to retry the push (up to a configured number of times).
		 *
		 * @Route("bkr::push_url", fallback="/webhooks/buckaroo", methods={"POST"})
		 * @see https://docs.buckaroo.io/docs/integration-push-messages
		 * @param Request $request
		 * @return Response
		 */
		public function handlePush(Request $request): Response {
			// Buckaroo pushes JSON; decode the body to extract the transaction key.
			$body = json_decode($request->getContent(), true);
			
			// Validate json
			if (json_last_error() !== JSON_ERROR_NONE) {
				error_log('Buckaroo push: invalid JSON payload');
				return new Response('OK', 200, ['Content-Type' => 'text/plain']);
			}
			
			// The key is nested: body.Transaction.Key
			$transactionId = $body['Transaction']['Key'] ?? null;
			
			// Some older configurations may also send as form-encoded with brq_transactions
			if (empty($transactionId)) {
				$transactionId = $request->request->get('brq_transactions') ?? $request->query->get('brq_transactions');
			}
			
			// Return 200 to prevent Buckaroo from retrying a malformed push indefinitely.
			// Log the issue so it can be investigated.
			if (empty($transactionId)) {
				error_log('Buckaroo push: missing transaction key in payload: ' . $request->getContent());
				return new Response('OK', 200, ['Content-Type' => 'text/plain']);
			}
			
			try {
				// Fetch the authoritative payment state from the Buckaroo API.
				$state = $this->buckaroo->exchange($transactionId, ['action' => 'push']);
				
				// Notify listeners (e.g. order management) of the updated payment state.
				$this->signal->emit($state);
			} catch (PaymentExchangeException $exception) {
				// Log the failure but return 200 — if we return non-200, Buckaroo retries,
				// which could cause a flood of retries for a persistent API error.
				error_log('Buckaroo push exchange failed: ' . $exception->getMessage() . ' (' . $exception->getErrorId() . ')');
			}
			
			// Buckaroo requires HTTP 200 to acknowledge receipt.
			return new Response('OK', 200, ['Content-Type' => 'text/plain']);
		}
	}