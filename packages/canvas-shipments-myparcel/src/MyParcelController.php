<?php
	
	namespace Quellabs\Shipments\MyParcel;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class MyParcelController {
		
		/**
		 * @var Driver MyParcel driver
		 */
		private Driver $driver;
		
		/**
		 * Emitted after every parcel status change, carrying the updated ShipmentState.
		 * Listeners (e.g. order management) should subscribe to act on shipment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $driver
		 */
		public function __construct(Driver $driver) {
			$this->driver = $driver;
			$this->signal = new Signal('shipment_exchange');
		}
		
		/**
		 * Handles MyParcel webhook events — asynchronous server-to-server shipment status
		 * notifications POSTed by MyParcel whenever a parcel status changes.
		 *
		 * MyParcel sends a minimal payload: { "shipment_id": 123456789, "account_id": 1234, "shop_id": 5678 }
		 * The full shipment state is fetched via exchange() on every event.
		 *
		 * MyParcel does not sign webhook requests. Restrict this endpoint by IP or place it
		 * behind a secret path segment configured in your MyParcel panel.
		 *
		 * Respond with HTTP 200 to acknowledge. Any non-200 triggers retries.
		 *
		 * @see https://developer.myparcel.nl/api-reference/12.webhooks.html
		 *
		 * @Route("myparcel::webhook_url", fallback="/webhooks/myparcel", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function handleWebhook(Request $request): Response {
			try {
				// Fetch body
				$rawBody = $request->getContent();
				
				// Missing body error
				if (empty($rawBody) || !is_string($rawBody)) {
					return new JsonResponse('Empty response', 400);
				}
				
				// Decode body
				$body = json_decode($rawBody, true);
				
				// JSON decode error checking
				if (json_last_error() !== JSON_ERROR_NONE) {
					return new JsonResponse('Invalid JSON (' . json_last_error_msg() . ')', 400);
				}
				
				if (!is_array($body)) {
					return new JsonResponse('Invalid JSON: expected an object', 400);
				}
				
				// MyParcel sends only the shipment ID, not the full parcel state
				$shipmentId = $body['shipment_id'] ?? null;
				
				if (empty($shipmentId) || !is_numeric($shipmentId)) {
					return new JsonResponse('Missing or invalid shipment_id', 400);
				}
				
				// Fetch the current state from MyParcel — unavoidable because the webhook
				// body contains only the ID, not the full shipment data
				$state = $this->driver->exchange((string)$shipmentId);
				
				// Notify listeners (e.g. order management) of the updated shipment state
				$this->signal->emit($state);
			} catch (ShipmentExchangeException $exception) {
				// Log but return 200 to prevent MyParcel from retrying indefinitely.
				// Missed events can be recovered by calling ShipmentRouter::exchange() explicitly.
				error_log('MyParcel webhook processing failed: ' . $exception->getMessage());
			}
			
			return new Response('OK', 200, ['Content-Type' => 'text/plain']);
		}
		
		/**
		 * Handles a manual status refresh request — useful for reconciling missed webhooks
		 * or providing a "refresh tracking" button in an admin UI.
		 *
		 * This is NOT called by MyParcel; it is an internal endpoint your own frontend/backend
		 * can hit when it suspects a status is stale.
		 *
		 * @Route("myparcel::refresh_url", fallback="/shipments/myparcel/refresh/{shipmentId}", methods={"GET"})
		 * @param Request $request
		 * @param string $shipmentId
		 * @return Response
		 */
		public function handleRefresh(Request $request, string $shipmentId): Response {
			try {
				// Re-fetch the current state from MyParcel and emit the signal,
				// giving subscribers the same flow as a real webhook event
				$state = $this->driver->exchange($shipmentId);
				
				// Notify listeners of the refreshed state
				$this->signal->emit($state);
				
				return new JsonResponse([
					'shipmentId'  => $state->parcelId,
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