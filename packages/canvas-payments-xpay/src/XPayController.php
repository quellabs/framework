<?php
	
	namespace Quellabs\Payments\XPay;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class XPayController {
		
		/**
		 * @var Driver XPay driver instance
		 */
		private Driver $xpay;
		
		/**
		 * Emitted after a payment state change, carrying the updated PaymentState.
		 * Listeners (e.g. order management) should subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $xpay
		 */
		public function __construct(Driver $xpay) {
			$this->xpay   = $xpay;
			$this->signal = new Signal('payment_exchange');
		}
		
		/**
		 * Handles the XPay return URL — called when the hosted payment page redirects
		 * the shopper back after completing (or cancelling) a payment.
		 *
		 * XPay appends the following query parameters to the configured resultUrl:
		 *   orderId       = your order reference (PaymentRequest::$reference)
		 *   operationId   = XPay-assigned operation identifier for this transaction
		 *   channel       = channel string (e.g. 'ECOMMERCE')
		 *   securityToken = token from the original createOrder response (should be verified)
		 *   esito         = informational outcome string (do NOT rely on this for payment status)
		 *
		 * We always fetch the authoritative state via GET /orders/{orderId}; we do not
		 * rely on the esito/operationResult query parameters as these are not HMAC-signed.
		 *
		 * Race condition note: the shopper may return before XPay has finalised processing.
		 * Status PENDING is normal on return — the final status arrives via push notification.
		 *
		 * @Route("xpay::return_url", fallback="/payment/return/xpay", methods={"GET"})
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/docs/hosted-payment-page/
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			// orderId is our transactionId — the PaymentRequest::$reference passed at initiation
			$orderId = $request->query->get('orderId');
			
			if (empty($orderId)) {
				return new JsonResponse("Missing parameter 'orderId'", 400);
			}
			
			try {
				// Fetch authoritative payment state from XPay API
				$state  = $this->xpay->exchange($orderId, ['action' => 'return']);
				$config = $this->xpay->getConfig();
				
				// Notify listeners of the updated payment state
				$this->signal->emit($state);
				
				// Route the shopper based on payment outcome.
				// Cancelled: redirect to cancel URL.
				// Pending or paid: redirect to the success/thank-you page — the application
				// layer should show a "payment pending" message for pending states.
				if ($state->state === PaymentStatus::Canceled) {
					$redirectUrl = $config['return_url_cancel'];
				} elseif ($state->state === PaymentStatus::Failed) {
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
		 * Handles XPay push notifications — asynchronous server-to-server callbacks
		 * POSTed by XPay after a payment status change (completion, refund, etc.).
		 *
		 * XPay push payload (JSON, application/json):
		 * {
		 *   "eventId":      "<UUID>",
		 *   "eventTime":    "<ISO 8601>",
		 *   "securityToken": "<token from createOrder response>",
		 *   "operation": {
		 *     "orderId":         "<your order reference>",
		 *     "operationId":     "<XPay operation id>",
		 *     "operationType":   "CAPTURE",
		 *     "operationResult": "AUTHORIZED",
		 *     "operationAmount": "3545",
		 *     "operationCurrency": "EUR",
		 *     ...
		 *   }
		 * }
		 *
		 * The securityToken in the push body matches the one returned by createOrder.
		 * Storing and verifying it is strongly recommended but not enforced here to avoid
		 * blocking legitimate pushes when the token is not stored (e.g. after a server restart).
		 * Implement token verification in the application layer via the signal listener.
		 *
		 * We always call the API for the authoritative state rather than trusting the push body.
		 *
		 * XPay requires an HTTP 200 response to acknowledge receipt. Any non-200 response
		 * causes XPay to retry the push.
		 *
		 * @Route("xpay::push_url", fallback="/webhooks/xpay", methods={"POST"})
		 * @see https://developer.nexigroup.com/xpayglobal/en-EU/api/notification-api-v1/
		 * @param Request $request
		 * @return Response
		 */
		public function handlePush(Request $request): Response {
			$body = json_decode($request->getContent(), true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				error_log('XPay push: invalid JSON payload');
				return new Response('OK', 200, ['Content-Type' => 'text/plain']);
			}
			
			// orderId lives inside the nested operation object
			$orderId = $body['operation']['orderId'] ?? null;
			
			if (empty($orderId)) {
				error_log('XPay push: missing orderId in payload: ' . $request->getContent());
				return new Response('OK', 200, ['Content-Type' => 'text/plain']);
			}
			
			try {
				// Fetch the authoritative payment state from the XPay API
				$state = $this->xpay->exchange($orderId, [
					'action'      => 'push',
					'operationId' => $body['operation']['operationId'] ?? null,
				]);
				
				// Notify listeners of the updated payment state
				$this->signal->emit($state);
			} catch (PaymentExchangeException $exception) {
				// Log but return 200 — a non-200 response causes XPay to retry,
				// which could flood retries for a persistent API error.
				error_log('XPay push exchange failed: ' . $exception->getMessage() . ' (' . $exception->getErrorId() . ')');
			}
			
			// XPay requires HTTP 200 to acknowledge receipt
			return new Response('OK', 200, ['Content-Type' => 'text/plain']);
		}
	}