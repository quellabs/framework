<?php
	
	namespace Quellabs\Payments\Stripe;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\WebhookValidationException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class StripeController {
		
		private Driver $stripe;
		
		/**
		 * Emitted after a payment state change, carrying the updated PaymentState.
		 * Listeners (e.g. order management) should subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $stripe
		 */
		public function __construct(Driver $stripe) {
			$this->stripe = $stripe;
			$this->signal = new Signal("payment_exchange");
		}
		
		/**
		 * Handles the Stripe return URL — called when the buyer completes or cancels at Stripe.
		 *
		 * Stripe appends the Checkout Session ID to the return URL using the {CHECKOUT_SESSION_ID}
		 * placeholder configured on session creation. The action is determined by which URL Stripe
		 * redirected to: success_url (action=return) or cancel_url (action=cancel).
		 *
		 * Unlike PayPal's capture step, Stripe auto-captures on session completion, so exchange()
		 * here only reads state rather than submitting a capture.
		 *
		 * @Route("stripe::return_url", fallback="/payment/return/stripe", methods={"GET"})
		 * @see https://stripe.com/docs/api/checkout/sessions/retrieve
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			// Stripe appends the session ID via the {CHECKOUT_SESSION_ID} placeholder
			$sessionId = $request->query->get('session_id');
			
			if (empty($sessionId)) {
				return new JsonResponse("Missing parameter 'session_id'", 400);
			}
			
			try {
				$action   = $request->query->get('action', 'return');
				$response = $this->stripe->exchange($sessionId, [
					'action' => $action,
				]);
				
				// requires_action — buyer needs to complete 3DS or similar.
				// Redirect them back to Stripe's next_action URL.
				// No signal emitted — this is not a payment outcome.
				if ($response->state === PaymentStatus::Redirect) {
					$redirectUrl = $response->metadata['redirectUrl'] ?? null;
					
					if (!$redirectUrl) {
						return new JsonResponse("Missing redirect URL", 500);
					}
					
					return new RedirectResponse($redirectUrl);
				}
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				// Redirect the buyer to the configured post-payment page
				$config = $this->stripe->getConfig();
				
				if ($response->state === PaymentStatus::Canceled) {
					$redirectUrl = $config['cancel_return_url'];
				} else {
					$redirectUrl = $config['return_url'];
				}
				
				if (empty($redirectUrl)) {
					return new JsonResponse("Missing return URL configuration", 500);
				}
				
				return new RedirectResponse($redirectUrl);
			} catch (PaymentExchangeException $exception) {
				return new JsonResponse($exception->getMessage() . " (" . $exception->getErrorId() . ")", 502);
			}
		}
		
		/**
		 * Handles Stripe webhook notifications — asynchronous server-to-server payment status
		 * updates POSTed by Stripe after a transaction completes or changes state.
		 *
		 * Stripe verifies delivery by requiring a 2xx response within 30 seconds.
		 * Failure to respond 2xx causes Stripe to retry with exponential back-off (up to 3 days).
		 *
		 * Only payment_intent.* events are acted on; others are acknowledged and ignored.
		 *
		 * Signature verification is performed locally using HMAC-SHA256 — no outbound API call
		 * is required, unlike PayPal's /v1/notifications/verify-webhook-signature endpoint.
		 *
		 * @Route("stripe::webhook_url", fallback="/webhooks/stripe", methods={"POST"})
		 * @see https://stripe.com/docs/webhooks
		 * @see https://stripe.com/docs/webhooks/signatures
		 * @param Request $request
		 * @return Response
		 */
		public function handleWebhook(Request $request): Response {
			try {
				$parsed = $this->parseWebhookRequest($request);
			} catch (WebhookValidationException $exception) {
				return new JsonResponse($exception->getMessage(), $exception->getStatusCode());
			}
			
			// Only process payment_intent events. Other event types (checkout.session.*,
			// charge.*, customer.*) are acknowledged with 200 but do not trigger a signal.
			if (!str_starts_with($parsed['eventType'], 'payment_intent.')) {
				return new JsonResponse("OK");
			}
			
			return $this->processWebhookPaymentIntent($parsed['paymentIntentId'], $parsed['eventType']);
		}
		
		/**
		 * Verifies the webhook signature and extracts the payment context from the raw request.
		 * @param Request $request
		 * @return array{payload: array, eventType: string, paymentIntentId: string|null}
		 * @throws WebhookValidationException on any validation or verification failure
		 */
		private function parseWebhookRequest(Request $request): array {
			// Read the raw body before any decoding — the signature is computed over the raw bytes
			$rawBody = $request->getContent();
			
			if (empty($rawBody)) {
				throw new WebhookValidationException("Empty request body");
			}
			
			// Stripe sends the signature in a single Stripe-Signature header
			$signatureHeader = $request->headers->get('stripe-signature', '');
			
			if (empty($signatureHeader)) {
				throw new WebhookValidationException("Missing Stripe-Signature header");
			}
			
			// Reject if the HMAC signature cannot be verified locally
			if (!$this->stripe->verifyWebhookSignature($signatureHeader, $rawBody)) {
				throw new WebhookValidationException("Webhook signature verification failed");
			}
			
			$payload = json_decode($rawBody, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new WebhookValidationException("Invalid JSON");
			}
			
			// For payment_intent.* events, data.object is the PaymentIntent
			$intentObject    = $payload['data']['object'] ?? [];
			$paymentIntentId = $intentObject['id'] ?? null;
			
			return [
				'payload'         => $payload,
				'eventType'       => $payload['type'] ?? '',
				'paymentIntentId' => $paymentIntentId,
			];
		}
		
		/**
		 * Processes a verified payment_intent.* webhook event.
		 *
		 * Calls the payment exchange with the PaymentIntent ID as the transactionId, emits the
		 * resulting PaymentState to subscribers, and returns 200 OK. Returns 500 on exchange
		 * failure so Stripe retries with exponential back-off.
		 *
		 * @param string|null $paymentIntentId The PaymentIntent ID from the webhook payload
		 * @param string      $eventType       The Stripe event type (e.g. payment_intent.succeeded)
		 * @return Response
		 */
		private function processWebhookPaymentIntent(?string $paymentIntentId, string $eventType): Response {
			// Acknowledge but skip — we cannot reconstruct the payment context without the intent ID
			if (empty($paymentIntentId)) {
				return new JsonResponse("OK");
			}
			
			try {
				$response = $this->stripe->exchange($paymentIntentId, [
					'action'          => 'webhook',
					'paymentIntentId' => $paymentIntentId,
					'eventType'       => $eventType,
				]);
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				return new JsonResponse("OK");
			} catch (PaymentExchangeException $exception) {
				// Return 500 so Stripe retries — the failure was on our side, not a bad payload
				return new JsonResponse($exception->getMessage() . " (" . $exception->getErrorId() . ")", 500);
			}
		}
	}