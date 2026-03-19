<?php
	
	namespace Quellabs\Payments\Paypal;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\WebhookValidationException;
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
					$redirectUrl = $response->metadata['redirectUrl'] ?? null;
					
					if (!$redirectUrl) {
						return new JsonResponse("Missing redirect URL", 500);
					}
					
					return new RedirectResponse($redirectUrl);
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
				
				if (empty($redirectUrl)) {
					return new JsonResponse("Missing return URL configuration", 500);
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
			try {
				// Verify the signature and parse the payload into a normalized structure.
				// Throws WebhookValidationException on any validation failure.
				$parsed = $this->parseWebhookRequest($request);
			} catch (WebhookValidationException $exception) {
				return new JsonResponse($exception->getMessage(), $exception->getStatusCode());
			}
			
			// Only process capture-level events. Other event types (CHECKOUT.ORDER.*, BILLING.*)
			// are acknowledged with 200 but do not trigger a signal.
			if (!str_starts_with($parsed['eventType'], 'PAYMENT.CAPTURE.')) {
				return new JsonResponse("OK");
			}
			
			return $this->processWebhookCapture($parsed['orderId'], $parsed['captureId']);
		}
		
		/**
		 * Verifies the webhook signature and extracts the capture context from the raw request.
		 * Reads the raw body, validates the PayPal signature headers, decodes the JSON payload,
		 * and resolves the order ID from the capture's HATEOAS "up" link.
		 * @param Request $request
		 * @return array{payload: array, eventType: string, captureId: string|null, orderId: string|null}
		 * @throws WebhookValidationException on any validation or verification failure
		 */
		private function parseWebhookRequest(Request $request): array {
			// Read the raw body before any decoding — the signature is computed over the raw bytes
			$rawBody = $request->getContent();
			
			if (empty($rawBody)) {
				throw new WebhookValidationException("Empty request body");
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
				throw new WebhookValidationException("Webhook signature verification failed");
			}
			
			// Validate the body is JSON
			$payload   = json_decode($rawBody, true);
			
			// Validate the body is JSON
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new WebhookValidationException("Invalid JSON");
			}
			
			// Extract the capture resource and its ID
			$captureResource = $payload['resource'] ?? [];
			$captureId       = $captureResource['id'] ?? null;
			
			// Retrieve the order ID from the capture's supplementary_data links.
			// PayPal embeds it as a HATEOAS link relation "up" pointing to the order.
			$orderId = null;
			
			foreach ($captureResource['links'] ?? [] as $link) {
				if ($link['rel'] === 'up') {
					// href is e.g. https://api.paypal.com/v2/checkout/orders/{orderId}
					$path = parse_url($link['href'], PHP_URL_PATH);

					if ($path !== false) {
						$orderId = basename($path);
					}

					break;
				}
			}
			
			return [
				'payload'   => $payload,
				'eventType' => $payload['event_type'] ?? '',
				'captureId' => $captureId,
				'orderId'   => $orderId,
			];
		}
		
		/**
		 * Processes a verified PAYMENT.CAPTURE.* webhook event.
		 *
		 * Calls the payment exchange with the resolved order and capture IDs,
		 * emits the resulting PaymentState to subscribers, and returns a 200 OK.
		 * Returns 500 on exchange failure so PayPal retries with exponential back-off.
		 *
		 * @param string|null $orderId   The order ID resolved from the capture's HATEOAS "up" link
		 * @param string|null $captureId The capture ID from the webhook resource payload
		 * @return Response
		 */
		private function processWebhookCapture(?string $orderId, ?string $captureId): Response {
			// Acknowledge but skip — we cannot reconstruct the order context without the ID
			if (empty($orderId)) {
				return new JsonResponse("OK");
			}
			
			try {
				// Call the driver's exchange to convert raw data to PaymentState
				$response = $this->paypal->exchange($orderId, [
					'action'    => 'webhook',
					'captureId' => $captureId,
				]);
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				// Send OK
				return new JsonResponse("OK");
			} catch (PaymentExchangeException $exception) {
				// Return 500 so PayPal retries — the failure was on our side, not a bad payload
				return new JsonResponse($exception->getMessage() . " (" . $exception->getErrorId() . ")", 500);
			}
		}
	}