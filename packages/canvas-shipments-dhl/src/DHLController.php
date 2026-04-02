<?php
	
	namespace Quellabs\Shipments\DHL;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class DHLController {
		
		/**
		 * @var Driver DHL driver
		 */
		private Driver $dhl;
		
		/**
		 * Emitted after every parcel status change, carrying the updated ShipmentState.
		 * Listeners (e.g. order management) should subscribe to act on shipment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $dhl
		 */
		public function __construct(Driver $dhl) {
			$this->dhl = $dhl;
			$this->signal = new Signal('shipment_exchange');
		}
		
		/**
		 * Handles DHL webhook events — asynchronous server-to-server shipment status
		 * notifications POSTed by DHL when a parcel status changes.
		 *
		 * DHL's "Track & Trace Pusher" sends events in the following shape:
		 *   {
		 *     "barcode": "3SBPB0000094346",
		 *     "category": "DELIVERED",
		 *     "status": "DELIVERED",
		 *     "timestamp": "2026-04-02T13:45:00.000Z"
		 *   }
		 *
		 * Unlike MyParcel, DHL includes status data directly in the webhook payload.
		 * We still call exchange() to get the full event history and build a consistent
		 * ShipmentState, but the barcode is extracted from the payload rather than fetched.
		 *
		 * Security: DHL does not sign webhook requests. Restrict this endpoint by IP or
		 * place it behind a secret path segment configured in your DHL Track & Trace Pusher settings.
		 *
		 * Respond with HTTP 200 to acknowledge. Non-200 responses trigger retries.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/guide/chapters/06-track-and-trace-pusher.html
		 *
		 * @Route("dhl::webhook_url", fallback="/webhooks/dhl", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function handleWebhook(Request $request): Response {
			// Fetch and decode content
			$rawBody = $request->getContent();
			$body = json_decode($rawBody, true);
			
			// Error if invalid JSON
			if (json_last_error() !== JSON_ERROR_NONE) {
				return new JsonResponse('Invalid JSON (' . json_last_error_msg() . ')', 400);
			}
			
			// Fetch barcode
			$barcode = $body['barcode'] ?? null;
			
			// Error if no barcode present
			if (empty($barcode)) {
				return new JsonResponse('Missing or empty barcode', 400);
			}
			
			try {
				// Fetch full event history to build a consistent ShipmentState.
				// DHL's webhook payload includes status data, but exchange() ensures
				// we always have the complete event chain and consistent normalization.
				$state = $this->dhl->exchange($barcode);
				
				// Notify listeners (e.g. order management) of the updated shipment state
				$this->signal->emit($state);
			} catch (\Throwable $exception) {
				// Log but return 200 to prevent DHL from retrying indefinitely.
				// Missed events can be recovered by calling Driver::exchange() explicitly.
				error_log('DHL webhook processing failed: ' . $exception->getMessage());
			}
			
			return new Response('OK', 200, ['Content-Type' => 'text/plain']);
		}
		
		/**
		 * Handles a manual status refresh request — useful for reconciling missed webhooks
		 * or providing a "refresh tracking" button in an admin UI.
		 *
		 * This endpoint is NOT called by DHL; it is an internal endpoint your own
		 * frontend or backend can call when it suspects a status is stale.
		 *
		 * @Route("dhl::refresh_url", fallback="/shipments/dhl/refresh/{shipmentId}", methods={"GET"})
		 * @param string $shipmentId The DHL tracker code (barcode)
		 * @return Response
		 */
		public function handleRefresh(string $shipmentId): Response {
			try {
				// Call exchange to fetch state
				$state = $this->dhl->exchange($shipmentId);
				
				// Notify listeners of the refreshed state, giving subscribers the same
				// flow as a real webhook event
				$this->signal->emit($state);
				
				// Return data
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