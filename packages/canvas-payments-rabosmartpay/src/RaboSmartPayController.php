<?php
	
	namespace Quellabs\Payments\RaboSmartPay;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Payments\Contracts\PaymentExchangeException;
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
		 * @Route("rabosmartpay::return_url", fallback="/payment/return/rabosmartpay", methods={"GET"})
		 * @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		 * @param Request $request Incoming HTTP request from the shopper's browser
		 * @return Response Redirect to success or cancel page, or 400/502 on failure
		 */
		public function handleReturn(Request $request): Response {
			// Extract request data
			$orderStatus = $request->query->get('status', '');
			$signature = $request->query->get('signature', '');
			$merchantOrderId = $request->query->get('order_id', '');
			
			// Rabo Smart Pay always signs the return URL. Reject unsigned requests.
			if (empty($signature)) {
				return new JsonResponse('Missing signature on return URL', 400);
			}
			
			// Verify the signature
			$payload = implode(',', [$merchantOrderId, $orderStatus]);
			
			if (!$this->rabo->verifySignature($payload, $signature)) {
				return new JsonResponse('Invalid signature on return URL', 400);
			}
			
			// Fetch the config from the driver
			$config = $this->rabo->getConfig();
			
			// Route the shopper based on the status in the return URL.
			// The authoritative payment state is delivered via the webhook — this redirect
			// is purely a UX cue. IN_PROGRESS lands on the success page so the shopper
			// sees a confirmation rather than an error while the webhook is still pending.
			if (in_array(strtoupper($orderStatus), ['CANCELLED', 'EXPIRED', 'FAILURE'], true)) {
				return new RedirectResponse($config['cancel_return_url']);
			} else {
				return new RedirectResponse($config['return_url']);
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
			
			// Validate the passed JSON
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
				return new Response('Invalid JSON body', 400, ['Content-Type' => 'text/plain']);
			}
			
			// The notification token is required to perform the Status Pull call.
			$notificationToken = $body['authentication'] ?? '';
			
			// Validate the presence of an authentication token
			if (empty($notificationToken)) {
				return new Response("Missing 'authentication' in notification body", 400, ['Content-Type' => 'text/plain']);
			}
			
			// Verify the notification signature before processing any order data.
			// Payload: "{authentication},{expiry},{eventName},{poiId}"
			// Reject requests without a signature — Rabo Smart Pay always signs notifications.
			if (empty($body['signature'])) {
				error_log('Rabo Smart Pay: rejected webhook notification with no signature');
				return new Response('Missing signature', 400, ['Content-Type' => 'text/plain']);
			}
			
			// Verify the signature
			$signaturePayload = implode(',', [
				$body['authentication'] ?? '',
				$body['expiry'] ?? '',
				$body['eventName'] ?? '',
				$body['poiId'] ?? '',
			]);
			
			if (!$this->rabo->verifySignature($signaturePayload, $body['signature'])) {
				error_log('Rabo Smart Pay: rejected webhook notification with invalid signature');
				return new Response('Invalid signature', 400, ['Content-Type' => 'text/plain']);
			}
			
			// Pull all statuses
			try {
				$this->handleStatusPull($notificationToken);
			} catch (PaymentExchangeException $exception) {
				error_log('Rabo Smart Pay webhook: ' . $exception->getMessage() . ' (' . $exception->getErrorId() . ')');
			}
			
			// HTTP 200 acknowledges the notification. Rabo Smart Pay does not retry.
			return new Response('', 200);
		}
		
		/**
		 * Performs the Status Pull loop using a webhook notification token.
		 * Handles moreOrderResultsAvailable pagination and emits a PaymentState signal
		 * for each order result in the batch.
		 * @param string $notificationToken The authentication token from the webhook notification body
		 * @throws PaymentExchangeException
		 */
		private function handleStatusPull(string $notificationToken): void {
			// Rabo Smart Pay may paginate results across multiple pulls using the same token.
			$moreResultsAvailable = true;
			
			while ($moreResultsAvailable) {
				// Call the API to fetch the order statuses
				$result = $this->rabo->pullOrderStatuses($notificationToken);
				
				// If that failed, throw error
				if ($result['request']['result'] === 0) {
					throw new PaymentExchangeException(
						'rabosmartpay',
						$result['request']['errorId'],
						$result['request']['errorMessage']
					);
				}
				
				// Grab the response
				$pullData = $result['response'];
				
				// If true, loop and pull again with the same token to get the remaining results.
				$moreResultsAvailable = (bool)($pullData['moreOrderResultsAvailable'] ?? false);
				
				foreach ($pullData['orderResults'] ?? [] as $orderResult) {
					// omnikassaOrderId is Rabo Smart Pay's UUID — the transactionId for exchange() and refunds.
					$omnikassaOrderId = $orderResult['omnikassaOrderId'] ?? '';
					
					// Skip malformed results that are missing the order identifier.
					if (empty($omnikassaOrderId)) {
						continue;
					}
					
					// Resolve the order result into a PaymentState and broadcast it to listeners.
					// The full orderResult is passed so exchange() doesn't need to make an API call.
					$paymentState = $this->rabo->exchange($omnikassaOrderId, $orderResult);
					
					// Emit the payment state to listeners
					$this->signal->emit($paymentState);
				}
			}
		}
	}