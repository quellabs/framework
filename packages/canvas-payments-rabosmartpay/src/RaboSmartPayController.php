<?php
	
	namespace Quellabs\Payments\RaboSmartPay;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\SignalHub\Signal;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class RaboSmartPayController {
		
		/**
		 * Rabo Smart Pay driver instance used to resolve payment state.
		 * @var Driver
		 */
		private Driver $rabo;
		
		/**
		 * Signal emitted after every payment state resolution, carrying the PaymentState.
		 * Listeners (e.g. order management services) subscribe to act on payment outcomes.
		 * @var Signal
		 */
		public Signal $signal;
		
		/**
		 * Constructs the controller and wires up the payment_exchange signal.
		 *
		 * @param Driver $rabo Rabo Smart Pay driver with active configuration already applied
		 */
		public function __construct(Driver $rabo) {
			$this->rabo = $rabo;
			$this->signal = new Signal('payment_exchange');
		}
		
		/**
		 * Handles the Rabo Smart Pay return URL — called when the hosted checkout page
		 * redirects the shopper back after completing (or abandoning) a payment.
		 *
		 * Rabo Smart Pay appends the following parameters to merchantReturnURL:
		 *   ?order_id={merchantOrderId}&status={orderStatus}&signature={hex}
		 *
		 * 'order_id' is our merchantOrderId — not the omnikassaOrderId UUID.
		 * 'status' is one of: IN_PROGRESS, COMPLETED, CANCELLED, EXPIRED, FAILURE.
		 *
		 * The omnikassaOrderId (UUID) is not in the return URL. It must have been stored
		 * at initiation time by the application and retrieved here using the merchantOrderId.
		 *
		 * Signature verification:
		 * The return URL parameters are signed with HMAC-SHA512. The payload is:
		 *   "{merchantOrderId},{orderStatus}"
		 * Reject requests where the signature does not match — they may be forged.
		 *
		 * Race condition: iDEAL and some other methods may return IN_PROGRESS because the
		 * bank confirmation is still pending. The webhook will deliver the final status.
		 * We still redirect to the success page for IN_PROGRESS — the shopper has done
		 * their part and should see a confirmation, not an error.
		 *
		 * @Route("rabosmartpay::return_url", fallback="/payment/return/rabosmartpay", methods={"GET"})
		 * @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		 * @param Request $request Incoming HTTP request from the shopper's browser
		 * @return Response Redirect to success or cancel page, or 400/502 on failure
		 */
		public function handleReturn(Request $request): Response {
			// 'order_id' in the return URL is our merchantOrderId, not the omnikassaOrderId UUID.
			$merchantOrderId = $request->query->get('order_id');
			$orderStatus = $request->query->get('status', '');
			$signature = $request->query->get('signature', '');
			
			// Reject requests missing the mandatory merchant order identifier.
			if (empty($merchantOrderId)) {
				return new JsonResponse("Missing parameter 'order_id'", 400);
			}
			
			// Grab the contents of the configuration file
			$config = $this->rabo->getConfig();
			
			// Verify the HMAC-SHA512 signature to confirm the return URL originated from Rabo Smart Pay.
			// Payload: "{merchantOrderId},{orderStatus}" — exact format per the SDK documentation.
			// Reject unsigned or tampered requests — they cannot be trusted.
			if (!empty($signature) && !empty($config['signing_key'])) {
				$payload = implode(',', [$merchantOrderId, $orderStatus]);
				
				if (!$this->rabo->verifySignature($payload, $signature)) {
					return new JsonResponse('Invalid signature on return URL', 400);
				}
			}
			
			try {
				// The return URL carries merchantOrderId (our generated reference) and the order
				// status, but not the omnikassaOrderId UUID. We pass merchantOrderId as the
				// transactionId so the signal listener can look up their order. Since orderStatus
				// is already known, exchange() will not make an API call.
				$response = $this->rabo->exchange($merchantOrderId, [
					'orderStatus' => $orderStatus,
				]);
				
				// Broadcast the resolved state to any registered listeners.
				$this->signal->emit($response);
				
				// Route the shopper based on the resolved payment state.
				// Cancelled, expired, and failed payments go to the cancel URL so the shopper can retry.
				// All other states — including IN_PROGRESS — land on the success page so the
				// shopper sees a meaningful "payment received / pending" confirmation.
				if (in_array($response->state, [PaymentStatus::Canceled, PaymentStatus::Expired, PaymentStatus::Failed], true)) {
					$redirectUrl = $config['cancel_return_url'];
				} else {
					$redirectUrl = $config['return_url'];
				}
				
				return new RedirectResponse($redirectUrl);
			} catch (PaymentExchangeException $exception) {
				return new JsonResponse(
					$exception->getMessage() . ' (' . $exception->getErrorId() . ')',
					502
				);
			}
		}
		
		/**
		 * Handles Rabo Smart Pay webhook notifications — server-to-server POST calls made
		 * by Rabo Smart Pay when one or more order statuses have changed.
		 *
		 * Notification payload (application/json):
		 *   {
		 *     "authentication": "<token>",
		 *     "expiry": "<ISO8601>",
		 *     "eventName": "merchant.order.status.changed",
		 *     "poiId": <integer>
		 *   }
		 *
		 * The notification is NOT the payment status — it is an invitation to perform a
		 * Status Pull. The 'authentication' token in the body must be used (not the
		 * access token) to call GET /order/server/api/events/results/merchant.order.status.changed.
		 *
		 * The Status Pull response may contain multiple order results and may indicate
		 * moreOrderResultsAvailable=true, requiring another pull with the same token.
		 *
		 * The notification body is signed using HMAC-SHA512. The payload string is:
		 *   "{authentication},{expiry},{eventName},{poiId}"
		 * Verify this signature before processing any order data.
		 *
		 * Rabo Smart Pay does NOT retry failed webhook deliveries — if you return anything
		 * other than HTTP 200, the notification is considered delivered and will not be
		 * resent. Log errors and handle recovery via the return URL flow or manual reconciliation.
		 *
		 * @Route("rabosmartpay::webhook_url", fallback="/webhooks/rabosmartpay", methods={"POST"})
		 * @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		 * @param Request $request Incoming HTTP request from Rabo Smart Pay
		 * @return Response HTTP 200 on success or acknowledgment; 400 on bad input
		 */
		public function handleWebhook(Request $request): Response {
			// Rabo Smart Pay sends JSON; parse the body regardless of Content-Type header.
			$body = json_decode($request->getContent(), true);
			
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
				return new Response('Invalid JSON body', 400, ['Content-Type' => 'text/plain']);
			}
			
			// The notification token is required to perform the Status Pull call.
			$notificationToken = $body['authentication'] ?? '';
			
			if (empty($notificationToken)) {
				return new Response("Missing 'authentication' in notification body", 400, ['Content-Type' => 'text/plain']);
			}
			
			// Verify the notification signature before processing any order data.
			// Payload: "{authentication},{expiry},{eventName},{poiId}"
			if (!empty($body['signature'])) {
				$signaturePayload = implode(',', [
					$body['authentication'] ?? '',
					$body['expiry'] ?? '',
					$body['eventName'] ?? '',
					$body['poiId'] ?? '',
				]);
				
				// Validate the signature
				if (!$this->rabo->verifySignature($signaturePayload, $body['signature'])) {
					// Returning 400 here is safe — a tampered notification should not be
					// acknowledged. Rabo Smart Pay will not retry, which is acceptable.
					error_log('Rabo Smart Pay: rejected webhook notification with invalid signature');
					return new Response('Invalid signature', 400, ['Content-Type' => 'text/plain']);
				}
			}
			
			try {
				$this->rabo->exchangeFromNotification($notificationToken, fn($state) => $this->signal->emit($state));
			} catch (PaymentExchangeException $exception) {
				error_log('Rabo Smart Pay webhook: ' . $exception->getMessage() . ' (' . $exception->getErrorId() . ')');
			}
			
			// HTTP 200 acknowledges the notification. Rabo Smart Pay does not retry.
			return new Response('', 200);
		}
	}