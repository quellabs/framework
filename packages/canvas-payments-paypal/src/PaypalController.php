<?php
	
	namespace Quellabs\Payments\Paypal;
	
	use Quellabs\Canvas\Annotations\Route;
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
		 * PayPal appends the order ID as ?token={orderId} and the action as ?action=return|cancel.
		 * Captures the payment via the Orders v2 API and emits the resulting PaymentState.
		 * @Route("paypal::return_url", fallback="/payment/return/paypal", methods={"GET"})
		 * @see https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			// PayPal appends the order ID to the return URL as ?token={orderId}
			$token = $request->query->get('token');
			
			if (empty($token)) {
				return new JsonResponse("Missing parameter 'token'", 400);
			}
			
			try {
				$action   = $request->query->get('action');
				$response = $this->paypal->exchange($token, [
					'action' => $action
				]);
				
				// INSTRUMENT_DECLINED or PAYER_ACTION_REQUIRED — redirect the buyer back to PayPal
				// to choose a different payment method or complete additional authentication.
				// No signal emitted — this is not a payment outcome.
				if ($response->state === PaymentStatus::Redirect) {
					return new RedirectResponse($response->metadata['redirectUrl']);
				}
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				// Redirect the buyer to the configured post-payment page
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
		 * Handles PayPal webhook notifications — asynchronous server-to-server payment status
		 * updates POSTed by PayPal after a transaction completes or changes state.
		 *
		 * Replaces the NVP IPN handler. Key differences from IPN:
		 *   - Signature-based verification instead of echo-back
		 *   - Event-typed payloads (PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED, etc.)
		 *   - Only PAYMENT.CAPTURE.* events are acted on; others are acknowledged and ignored
		 *
		 * PayPal considers any 2xx response a successful delivery.
		 * Failure to respond 2xx causes PayPal to retry with exponential back-off.
		 *
		 * @Route("paypal::webhook_url", fallback="/webhooks/paypal", methods={"POST"})
		 * @see https://developer.paypal.com/docs/api/webhooks/v1/
		 * @see https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature_post
		 * @param Request $request
		 * @return Response
		 */
		public function handleWebhook(Request $request): Response {
			// Read the raw body before any decoding — the signature is computed over the raw bytes
			$rawBody = $request->getContent();
			
			if (empty($rawBody)) {
				return new JsonResponse("Empty request body", 400);
			}
			
			// Collect and lowercase all PayPal signature headers.
			// Symfony's Request::headers stores them with normalized keys, so we lowercase manually
			// to guard against any casing inconsistencies between PayPal's sender and the framework.
			$headers = [];
			
			foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $key) {
				$value = $request->headers->get($key);
				
				if ($value !== null) {
					$headers[$key] = $value;
				}
			}
			
			// Reject if the signature cannot be verified — this protects against spoofed requests
			if (!$this->paypal->verifyWebhookSignature($headers, $rawBody)) {
				return new JsonResponse("Webhook signature verification failed", 400);
			}
			
			$payload   = json_decode($rawBody, true);
			$eventType = $payload['event_type'] ?? '';
			
			// Only process capture-level events. Other event types (CHECKOUT.ORDER.*, BILLING.*)
			// are acknowledged with 200 but do not trigger a signal.
			if (!str_starts_with($eventType, 'PAYMENT.CAPTURE.')) {
				return new JsonResponse("OK");
			}
			
			// The capture resource is nested in the webhook payload
			$captureResource = $payload['resource'] ?? [];
			$captureId       = $captureResource['id'] ?? null;
			
			// Retrieve the order ID from the capture's supplementary_data links.
			// PayPal embeds it as a HATEOAS link relation "up" pointing to the order.
			$orderId = null;
			
			foreach ($captureResource['links'] ?? [] as $link) {
				if ($link['rel'] === 'up') {
					// href is e.g. https://api.paypal.com/v2/checkout/orders/{orderId}
					$orderId = basename(parse_url($link['href'], PHP_URL_PATH));
					break;
				}
			}
			
			if (empty($orderId)) {
				// Acknowledge but log — we cannot reconstruct the order context without the ID
				return new JsonResponse("OK");
			}
			
			try {
				$response = $this->paypal->exchange($orderId, [
					'action'    => 'webhook',
					'captureId' => $captureId,
				]);
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				return new JsonResponse("OK");
			} catch (PaymentExchangeException $exception) {
				// Return 500 so PayPal retries — the failure was on our side, not a bad payload
				return new JsonResponse($exception->getMessage() . " (" . $exception->getErrorId() . ")", 500);
			}
		}
	}