<?php
	
	namespace Quellabs\Shipments\SendCloud;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class SendCloudController {
		
		/**
		 * @var Driver SendCloud driver
		 */
		private Driver $sendcloud;
		
		/**
		 * Emitted after every parcel status change, carrying the updated ShipmentState.
		 * Listeners (e.g. order management) should subscribe to act on shipment outcomes.
		 *
		 * Example subscription in your application:
		 *   $controller->signal->connect(function(ShipmentState $state) {
		 *       // update order status, send customer email, etc.
		 *   });
		 *
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $sendcloud
		 */
		public function __construct(Driver $sendcloud) {
			$this->sendcloud = $sendcloud;
			$this->signal = new Signal('shipment_exchange');
		}
		
		/**
		 * Handles SendCloud webhook events — asynchronous server-to-server parcel status
		 * notifications POSTed by SendCloud whenever a parcel status changes.
		 *
		 * SendCloud delivers one event per webhook call. Each event body contains a 'parcel'
		 * object with the full current state. The signature in the Sendcloud-Signature header
		 * must be verified before processing.
		 *
		 * Respond with HTTP 200 to acknowledge. Any non-200 response triggers retries.
		 *
		 * @see https://docs.sendcloud.com/api/v2/#webhooks
		 *
		 * @Route("sendcloud::webhook_url", fallback="/webhooks/sendcloud", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function handleWebhook(Request $request): Response {
			$rawBody = $request->getContent();
			$signature = $request->headers->get('Sendcloud-Signature', '');
			
			// Verify the HMAC signature before processing anything.
			// An invalid signature may indicate a spoofed request — reject it.
			if (!$this->sendcloud->verifyWebhookSignature($rawBody, $signature)) {
				// Log with enough context to diagnose key mismatches without exposing the key.
				error_log('SendCloud webhook signature verification failed.');
				return new Response('Signature mismatch', 401);
			}
			
			// Decode the body
			$body = json_decode($rawBody, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				return new JsonResponse('Invalid JSON (' . json_last_error_msg() . ')', 400);
			}
			
			// SendCloud wraps the parcel data in a 'parcel' key
			$parcel = $body['parcel'] ?? null;
			
			if (empty($parcel)) {
				return new JsonResponse('Missing parcel data', 400);
			}
			
			try {
				// Build a normalised ShipmentState from the raw webhook payload.
				// We use buildStateFromParcel() directly rather than calling exchange() to
				// avoid a redundant API round-trip — the webhook already carries the full state.
				$state = $this->sendcloud->buildStateFromParcel($parcel);
				
				// Notify listeners (e.g. order management) of the updated shipment state
				$this->signal->emit($state);
			} catch (\Throwable $exception) {
				// Log the failure but still return 200 so SendCloud doesn't retry indefinitely.
				// Missed events must be recovered by calling ShipmentRouter::exchange() explicitly.
				error_log('SendCloud webhook processing failed: ' . $exception->getMessage());
			}
			
			return new Response('OK', 200, ['Content-Type' => 'text/plain']);
		}
		
		/**
		 * Handles a manual status refresh request — useful for reconciling missed webhooks
		 * or providing a "refresh tracking" button in an admin UI.
		 *
		 * This is NOT called by SendCloud; it is an internal endpoint your own frontend/backend
		 * can hit when it suspects a status is stale.
		 *
		 * @Route("sendcloud::refresh_url", fallback="/shipments/sendcloud/refresh/{parcelId}", methods={"GET"})
		 * @param Request $request
		 * @param string $parcelId
		 * @return Response
		 */
		public function handleRefresh(Request $request, string $parcelId): Response {
			try {
				// Re-fetch the current state from SendCloud and emit the signal,
				// giving subscribers the same flow as a real webhook event.
				$state = $this->sendcloud->exchange($parcelId);
				
				// Notify listeners of the refreshed state
				$this->signal->emit($state);
				
				return new JsonResponse([
					'parcelId'    => $state->parcelId,
					'reference'   => $state->reference,
					'status'      => $state->state->name,
					'trackingUrl' => $state->trackingUrl,
				]);
			} catch (ShipmentExchangeException $exception) {
				return new JsonResponse(
					$exception->getMessage() . ' (' . $exception->getErrorId() . ')',
					502
				);
			}
		}
	}