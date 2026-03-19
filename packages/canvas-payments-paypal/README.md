# PayPal Payment Provider

A PayPal payment provider for the Canvas framework. Part of the Canvas payments ecosystem.
Implements the modern PayPal Orders v2 REST API with webhook-based payment notifications.

## Installation

```bash
composer require quellabs/canvas-payments-paypal
```

## Architecture

This package sits between the PayPal REST API and your application. Your application only ever touches the contracts
layer — it never depends on this package directly. `PaymentRouter` (from `quellabs/canvas-payments`) discovers this
package automatically via composer metadata and routes payment calls to it.

```
Your Application
      │
      ▼
PaymentRouter               (quellabs/canvas-payments — discovery + routing)
      │
      ▼
PaymentProviderInterface    (quellabs/canvas-payments-contracts)
      │
      ▼
PayPal                      (this package — implements the interface)
      │
      ▼
PaypalGateway               (raw PayPal Orders v2 / Payments v2 REST API calls)
```

Webhook processing is decoupled from your application via signals. When PayPal sends a webhook notification, the
package emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and
handles it. The buyer return URL works the same way — both the return and cancel cases are handled by the same route
and emit the same signal, so your application does not need to distinguish between them at the routing level.

## Configuration

Create `config/paypal.php` in your Canvas application:

```php
return [
    'test_mode'         => true,
    'client_id'         => '',
    'client_secret'     => '',
    'webhook_id'        => '',
    'verify_ssl'        => true,
    'account_optional'  => true,
    'brand_name'        => '',
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
    'webhook_url'       => 'https://example.com/webhooks/paypal',
];
```

| Key                 | Required | Description                                                                                       |
|---------------------|----------|---------------------------------------------------------------------------------------------------|
| `test_mode`         | Yes      | Set to `true` for sandbox, `false` for production                                                 |
| `client_id`         | Yes      | REST API client ID from the PayPal Developer Dashboard                                            |
| `client_secret`     | Yes      | REST API client secret from the PayPal Developer Dashboard                                        |
| `webhook_id`        | Yes      | Webhook ID from your PayPal app settings — required for signature verification                    |
| `verify_ssl`        | No       | Whether to verify PayPal's SSL certificate. Always `true` in production. Defaults to `true`       |
| `account_optional`  | No       | When `true`, buyers can check out as a guest without a PayPal account. Defaults to `true`         |
| `brand_name`        | No       | Your store name shown on the PayPal checkout page. Leave empty to use your PayPal account name    |
| `return_url`        | Yes      | URL the customer is redirected to after a completed payment                                       |
| `cancel_return_url` | Yes      | URL the customer is redirected to after cancelling at PayPal                                      |
| `webhook_url`       | Yes      | Full URL PayPal POSTs webhook notifications to — must match the URL registered in your PayPal app |

## Usage

### Initiating a payment

Inject `PaymentRouter` via Canvas DI and call `initiate()`:

```php
use Quellabs\Payments\PaymentRouter;
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutController extends BaseController {

    public function __construct(private PaymentRouter $router) {}

    /**
     * @Route("...")
     */
    public function checkout(): void {
        $request = new PaymentRequest(
            paymentModule: 'paypal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
        );

        try {
            $result = $this->router->initiate($request);
            return $this->redirect($result->redirectUrl);
        } catch (PaymentInitiationException $e) {
            // handle error
        }
    }
}
```

### Handling refunds

Pass `amount: null` for a full refund, or a minor-unit integer for a partial refund.

When your `payment_exchange` listener receives a `PaymentStatus::Paid` state, store
`$state->metadata['captureId']` — you'll need it as `RefundRequest::$transactionId`.

```php
// In your payment_exchange listener — store the capture ID when the payment succeeds
public function onPaymentExchange(PaymentState $state): void {
    if ($state->state === PaymentStatus::Paid) {
        $this->orderRepository->updateCaptureId(
            $state->transactionId,
            $state->metadata['captureId']
        );
    }
}

// Full refund
$request = new RefundRequest(
    transactionId: $order->captureId,   // retrieved from your orders table
    paymentModule: 'paypal',
    amount:        null,                // null = full refund
    currency:      'EUR',
    description:   'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    transactionId: $order->captureId,   // retrieved from your orders table
    paymentModule: 'paypal',
    amount:        500,                 // in minor units — €5.00
    currency:      'EUR',
    description:   'Partial refund for order #12345',
);

try {
    $result = $this->router->refund($request);
    echo $result->refundId;
} catch (PaymentRefundException $e) {
    // handle error
}
```

### Listening for payment state changes

```php
use Quellabs\Canvas\Annotations\ListenTo;
use Quellabs\Payments\Contracts\PaymentState;
use Quellabs\Payments\Contracts\PaymentStatus;

class OrderService {

    /**
     * @ListenTo("payment_exchange")
     */
    public function onPaymentExchange(PaymentState $state): void {
        match ($state->state) {
            PaymentStatus::Paid      => $this->markPaid($state->transactionId),
            PaymentStatus::Canceled  => $this->markCanceled($state->transactionId),
            PaymentStatus::Failed    => $this->markFailed($state->transactionId),
            default                  => null,
        };
    }
}
```

## PayPal-specific quirks

### Two transaction identifiers

PayPal uses two different identifiers across the payment lifecycle:

- **Order ID** — created by `POST /v2/checkout/orders` and returned by `initiate()` as
  `InitiateResult::$transactionId`. Used to drive the checkout flow and passed to `exchange()`.
- **Capture ID** — available in `PaymentState::$metadata['captureId']` when a `PaymentStatus::Paid` event fires.
  **Persist this value** — it is required as `RefundRequest::$transactionId` for refunds and `getRefunds()`.

### Webhooks vs. return URL

PayPal notifies your application of payment state changes in two independent ways:

- **Webhooks** — a server-to-server POST from PayPal to `webhook_url`, verified by cryptographic signature.
  This is the authoritative source of truth and may arrive before or after the buyer returns to your site.
  Only `PAYMENT.CAPTURE.*` events trigger a signal; all other event types are acknowledged and ignored.
- **Return URL** — a browser redirect after the buyer completes or cancels at PayPal.

Both routes call `exchange()` and emit the `payment_exchange` signal. Your application should be idempotent when
handling this signal, as it may fire twice for the same transaction.

### Webhook setup

Register your `webhook_url` in the [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/) under
Apps & Credentials → your app → Webhooks. Subscribe at minimum to these event types:

- `PAYMENT.CAPTURE.COMPLETED`
- `PAYMENT.CAPTURE.DENIED`
- `PAYMENT.CAPTURE.REFUNDED`
- `PAYMENT.CAPTURE.REVERSED`

Copy the resulting Webhook ID into your `config/paypal.php` as `webhook_id`. Without it, all webhook
notifications will be rejected.

### Refund type determination

Pass `amount: null` to `RefundRequest` for a full refund. The driver omits the amount field from the API request,
which causes PayPal to refund the full captured amount internally. Pass a minor-unit integer for a partial refund.
Do not attempt to calculate the refundable amount yourself and pass it as a full refund — use `null` instead.

## License

MIT