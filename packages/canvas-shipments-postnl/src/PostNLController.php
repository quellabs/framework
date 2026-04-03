<?php

namespace Quellabs\Shipments\PostNL;

use Quellabs\Canvas\Annotations\Route;
use Quellabs\Shipments\Contracts\ShipmentExchangeException;
use Quellabs\SignalHub\Signal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PostNLController {

	/**
	 * @var Driver PostNL driver
	 */
	private Driver $postnl;

	/**
	 * Emitted after every parcel status change, carrying the updated ShipmentState.
	 * Listeners (e.g. order management) should subscribe to act on shipment outcomes.
	 * @var Signal
	 */
	public Signal $signal;

	/**
	 * HMAC-SHA256 secret used to verify incoming webhook signatures.
	 * Configure this in your PostNL Developer Portal webhook subscription.
	 * @var string
	 */
	private string $webhookSecret;

	/**
	 * Constructor
	 * @param Driver $postnl
	 */
	public function __construct(Driver $postnl) {
		$this->postnl = $postnl;
		$this->signal = new Signal('shipment_exchange');
		$this->webhookSecret = $postnl->getConfig()['webhook_secret'] ?? '';
	}

	/**
	 * Handles PostNL webhook events — asynchronous server-to-server shipment status
	 * notifications POSTed by PostNL whenever a parcel status changes.
	 *
	 * PostNL signs webhook requests using HMAC-SHA256. The signature is sent in the
	 * 'X-PostNL-Signature' header. Verify it against the raw request body and your
	 * shared webhook secret before processing the payload.
	 *
	 * Payload structure:
	 *   {
	 *     "Barcode": "3SBOL...",
	 *     "Status": { "PhaseCode": 3, "StatusCode": 13, "Description": "..." },
	 *     "TimeStamp": "2026-04-02T09:45:00"
	 *   }
	 *
	 * Respond with HTTP 200 to acknowledge. Any non-200 triggers retries.
	 *
	 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/status/track-trace-webhooks
	 *
	 * @Route("postnl::webhook_url", fallback="/webhooks/postnl", methods={"POST"})
	 * @param Request $request
	 * @return Response
	 */
	public function handleWebhook(Request $request): Response {
		$rawBody = $request->getContent();

		// Verify HMAC signature when a secret is configured
		if ($this->webhookSecret !== '') {
			$signature = $request->headers->get('X-PostNL-Signature', '');
			$expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

			if (!hash_equals($expected, strtolower($signature))) {
				return new JsonResponse('Invalid signature', 401);
			}
		}

		$body = json_decode($rawBody, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new JsonResponse('Invalid JSON (' . json_last_error_msg() . ')', 400);
		}

		$barcode = $body['Barcode'] ?? null;

		if (empty($barcode)) {
			return new JsonResponse('Missing or invalid Barcode', 400);
		}

		try {
			// Fetch the full current state from the Status API.
			// The webhook payload contains a status snapshot, but exchange() gives
			// us a fully normalised ShipmentState including all metadata.
			$state = $this->postnl->exchange($barcode);

			// Notify listeners (e.g. order management) of the updated shipment state
			$this->signal->emit($state);
		} catch (\Throwable $exception) {
			// Log but return 200 to prevent PostNL from retrying indefinitely.
			// Missed events can be recovered by calling exchange() explicitly.
			error_log('PostNL webhook processing failed: ' . $exception->getMessage());
		}

		return new Response('OK', 200, ['Content-Type' => 'text/plain']);
	}

	/**
	 * Handles a manual status refresh request — useful for reconciling missed webhooks
	 * or providing a "refresh tracking" button in an admin UI.
	 *
	 * This is NOT called by PostNL; it is an internal endpoint your own frontend or
	 * backend can call when a status is suspected to be stale.
	 *
	 * @Route("postnl::refresh_url", fallback="/shipments/postnl/refresh/{barcode}", methods={"GET"})
	 * @param Request $request
	 * @param string $barcode The PostNL barcode from ShipmentResult::$parcelId
	 * @return Response
	 */
	public function handleRefresh(Request $request, string $barcode): Response {
		try {
			// Re-fetch the current state and emit the signal,
			// giving subscribers the same flow as a real webhook event
			$state = $this->postnl->exchange($barcode);

			// Notify listeners of the refreshed state
			$this->signal->emit($state);

			return new JsonResponse([
				'barcode'     => $state->parcelId,
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
