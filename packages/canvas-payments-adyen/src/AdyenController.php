<?php
	
	namespace Quellabs\Payments\Adyen;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class AdyenController {
		
		/**
		 * @var Driver Adyen driver
		 */
		private Driver $adyen;
		
		/**
		 * Emitted after a payment state change, carrying the updated PaymentState.
		 * Listeners (e.g. order management) should subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $adyen
		 */
		public function __construct(Driver $adyen) {
			$this->adyen  = $adyen;
			$this->signal = new Signal('payment_exchange');
		}
		
		/**
		 * Handles the Adyen return URL — called when the Drop-in redirects the shopper back
		 * after a redirect-based payment method (e.g. iDEAL, some 3DS flows).
		 *
		 * Adyen appends the following query parameters to the return URL:
		 *   ?sessionId=cs_...&redirectResult=...
		 *
		 * The redirectResult must be submitted to POST /payments/details to resolve the final
		 * payment status. For payment methods that complete inline (card without 3DS redirect),
		 * the Drop-in calls /payments/details itself and the shopper never hits this URL.
		 *
		 * @Route("adyen::return_url", fallback="/payment/return/adyen", methods={"GET"})
		 * @see https://docs.adyen.com/online-payments/build-your-integration/sessions-flow
		 * @param Request $request
		 * @return Response
		 */
		public function handleReturn(Request $request): Response {
			$sessionId      = $request->query->get('sessionId');
			$redirectResult = $request->query->get('redirectResult');
			
			if (empty($sessionId)) {
				return new JsonResponse("Missing parameter 'sessionId'", 400);
			}
			
			if (empty($redirectResult)) {
				return new JsonResponse("Missing parameter 'redirectResult'", 400);
			}
			
			try {
				$response = $this->adyen->exchange($sessionId, [
					'action'         => 'return',
					'redirectResult' => $redirectResult,
				]);
				
				// Notify listeners (e.g. order management) of the updated payment state
				$this->signal->emit($response);
				
				$config = $this->adyen->getConfig();
				
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
		 * Handles Adyen Standard webhooks — asynchronous server-to-server payment event
		 * notifications POSTed by Adyen after a payment, capture, refund, or cancellation.
		 *
		 * Adyen webhooks are the authoritative source of payment state and may arrive before
		 * or after the shopper returns to the return URL — they must be handled independently.
		 *
		 * Adyen requires an HTTP 200 response containing the literal string "[accepted]".
		 * Any other response causes Adyen to retry the notification.
		 *
		 * The HMAC signature in additionalData.hmacSignature must be verified before processing.
		 * @see https://docs.adyen.com/development-resources/webhooks
		 * @see https://docs.adyen.com/development-resources/webhooks/verify-hmac-signatures
		 *
		 * @Route("adyen::webhook_url", fallback="/webhooks/adyen", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function handleWebhook(Request $request): Response {
			$body = json_decode($request->getContent(), true);
			
			// Adyen wraps all notifications in a notificationItems array.
			// JSON and HTTP POST webhooks contain exactly one item; SOAP may contain up to six.
			$notificationItems = $body['notificationItems'] ?? [];
			
			if (empty($notificationItems)) {
				return new JsonResponse('Missing notificationItems', 400);
			}
			
			foreach ($notificationItems as $item) {
				$notification = $item['NotificationRequestItem'] ?? null;
				
				if (empty($notification)) {
					continue;
				}
				
				// Verify the HMAC signature before processing.
				// Never process a notification that fails validation — it may be forged.
				if (!$this->adyen->verifyWebhookSignature($notification)) {
					// Return 401 to signal the rejection without leaking details.
					// Adyen will retry — if retries also fail, investigate the HMAC key.
					return new JsonResponse('Webhook HMAC validation failed', 401);
				}
				
				// pspReference is the payment reference; merchantReference is your own order ID.
				$pspReference = $notification['pspReference'] ?? null;
				
				if (empty($pspReference)) {
					continue;
				}
				
				try {
					$response = $this->adyen->exchange($pspReference, [
						'action'       => 'webhook',
						'notification' => $notification,
					]);
					
					// Notify listeners (e.g. order management) of the updated payment state
					$this->signal->emit($response);
				} catch (PaymentExchangeException $exception) {
					// Log the failure but still return [accepted] so Adyen doesn't retry
					// indefinitely. The missed event must be recovered via the Customer Area.
					// Replace this with your application's logger.
					error_log('Adyen webhook exchange failed: ' . $exception->getMessage() . ' (' . $exception->getErrorId() . ')');
				}
			}
			
			// Adyen requires the literal string "[accepted]" — any other content causes retries.
			return new Response('[accepted]', 200, ['Content-Type' => 'text/plain']);
		}
	}