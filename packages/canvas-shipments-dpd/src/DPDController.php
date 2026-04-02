<?php
	
	namespace Quellabs\Shipments\DPD;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class DPDController {
		
		/**
		 * @var Driver DPD driver
		 */
		private Driver $dpd;
		
		/**
		 * Emitted after every parcel status change, carrying the updated ShipmentState.
		 * Listeners (e.g. order management) should subscribe to act on shipment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructor
		 * @param Driver $dpd
		 */
		public function __construct(Driver $dpd) {
			$this->dpd = $dpd;
			$this->signal = new Signal('shipment_exchange');
		}
		
		/**
		 * Handles a manual status refresh request — useful for reconciling shipment
		 * status when a polling strategy is preferred over push notifications.
		 *
		 * DPD's Shipper Webservice does not offer a webhook / push notification mechanism.
		 * Status updates must be polled via the ParcelLifeCycle Service. Call this endpoint
		 * from a scheduled job or from a "refresh tracking" action in your admin UI.
		 *
		 * @Route("dpd::refresh_url", fallback="/shipments/dpd/refresh/{shipmentId}", methods={"GET"})
		 * @param Request $request
		 * @param string $shipmentId The DPD parcel label number (14-digit barcode)
		 * @return Response
		 */
		public function handleRefresh(Request $request, string $shipmentId): Response {
			try {
				$state = $this->dpd->exchange($shipmentId);
				
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
