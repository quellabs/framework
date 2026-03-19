# Stripe Payment Provider

A Stripe payment provider for the Canvas framework. Part of the Canvas payments ecosystem.
Implements the Stripe Checkout Sessions and Payment Intents APIs with webhook-based payment notifications.

## Installation

```bash
composer require quellabs/canvas-payments-stripe
```

## Architecture

This package sits between the Stripe REST API and your application. Your application only ever touches the contracts
layer — it never depends on this package directly. `PaymentRouter` (from `quellabs/canvas-payments`) discovers this
package automatically via composer metadata and routes payment calls to it.

```
Your Application
      │
      ▼
PaymentRouter               (quellabs/canvas-payments — discovery + routing)
      │
      ▼
PaymentInterface            (quellabs/canvas-payments-contracts)
      │
      ▼
Stripe                      (this package — implements the interface)
      │
      ▼
StripeGateway               (raw Stripe Checkout Sessions / Payment Intents REST API calls)
```

Webhook processing is decoupled from your application via signals. When Stripe sends a webhook notification, the
package emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and
handles it. The buyer return URL works the same way — both the return and cancel cases are handled by the same route
and emit the same signal, so your application does not need to distinguish between them at the routing level.

## Configuration

Create `config/stripe.php` in your Canvas application:

```php
return [
    'test_mode'         => true,
    'secret_key'        => '',
    'publishable_key'   => '',
    'webhook_secret'    => '',
    'verify_ssl'        => true,
    'brand_name'        => '',
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
    'webhook_url'       => 'https://example.com/webhooks/stripe',
];
```

| Key                 | Required | Description                                                                                                |
|---------------------|----------|------------------------------------------------------------------------------------------------------------|
| `test_mode`         | Yes      | Set to `true` for test mode, `false` for production                                                        |
| `secret_key`        | Yes      | Secret API key from the Stripe Dashboard — `sk_test_*` for test, `sk_live_*` for production                |
| `publishable_key`   | No       | Publishable API key — only needed if your frontend interacts with Stripe.js directly                       |
| `webhook_secret`    | Yes      | Webhook signing secret (`whsec_*`) from your Stripe webhook endpoint — required for signature verification |
| `verify_ssl`        | No       | Whether to verify Stripe's SSL certificate. Always `true` in production. Defaults to `true`                |
| `brand_name`        | No       | Used as the payment statement descriptor (max 22 characters). Full branding is set in the Stripe Dashboard |
| `return_url`        | Yes      | URL the customer is redirected to after a completed payment                                                |
| `cancel_return_url` | Yes      | URL the customer is redirected to after cancelling at Stripe                                               |
| `webhook_url`       | Yes      | Full URL Stripe POSTs webhook events to — must match the URL registered in your Stripe webhook settings    |

## Usage

### Initiating a payment

Inject `PaymentInterface` via Canvas DI and call `initiate()`:

```php
use Quellabs\Payments\Contracts\PaymentInterface;
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutController extends BaseController {

    public function __construct(private PaymentInterface $router) {}

    /**
     * @Route("...")
     */
    public function checkout(): void {
        $request = new PaymentRequest(
            paymentModule: 'stripe',
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
// In your payment_exchange listener — store the PaymentIntent ID when the payment succeeds
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
    paymentModule: 'stripe',
    amount:        null,                      // null = full refund
    currency:      'EUR',
    description:   'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    transactionId: $order->captureId,   // retrieved from your orders table
    paymentModule: 'stripe',
    amount:        500,                       // in minor units — €5.00
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

## Stripe-specific quirks

### Two transaction identifiers

Stripe uses two different identifiers across the payment lifecycle:

- **Session ID** — created by `POST /v1/checkout/sessions` and returned by `initiate()` as
  `InitiateResult::$transactionId`. Stripe appends it to your `return_url` as `?session_id={cs_...}`
  so the return handler can retrieve the session without server-side storage.
- **PaymentIntent ID** — available in `PaymentState::$metadata['captureId']` when a `PaymentStatus::Paid`
  event fires. **Persist this value** — it is required as `RefundRequest::$transactionId` for refunds and
  `getRefunds()`.

### Webhooks vs. return URL

Stripe notifies your application of payment state changes in two independent ways:

- **Webhooks** — a server-to-server POST from Stripe to `webhook_url`, verified by HMAC-SHA256 signature locally
  (no outbound verification call required). This is the authoritative source of truth and may arrive before or
  after the buyer returns to your site. Only `payment_intent.*` events trigger a signal; all other event types
  are acknowledged and ignored.
- **Return URL** — a browser redirect after the buyer completes or cancels at Stripe.

Both routes call `exchange()` and emit the `payment_exchange` signal. Your application should be idempotent when
handling this signal, as it may fire twice for the same transaction.

### Webhook setup

Register your `webhook_url` in the [Stripe Dashboard](https://dashboard.stripe.com/webhooks) under
Developers → Webhooks → Add endpoint. Subscribe at minimum to these event types:

- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `payment_intent.canceled`
- `payment_intent.requires_action`

After creating the endpoint, reveal the signing secret (`whsec_*`) and copy it into your `config/stripe.php`
as `webhook_secret`. Without it, all webhook notifications will be rejected.

For local development, use the [Stripe CLI](https://stripe.com/docs/stripe-cli) to forward events to your
local server:

```bash
stripe listen --forward-to localhost:8000/webhooks/stripe
```

The CLI prints a temporary signing secret — use it as `webhook_secret` during development.

### Refund reason mapping

Stripe accepts only three refund reason values: `duplicate`, `fraudulent`, and `requested_by_customer`.
The driver maps the `description` field of `RefundRequest` automatically — descriptions containing the word
"duplicate" or "dubbel" map to `duplicate`, descriptions containing "fraud" or "fraude" map to `fraudulent`,
and everything else maps to `requested_by_customer`. Pass `amount: null` for a full refund; the driver omits
the amount field, which causes Stripe to refund the full captured amount internally.

### 3DS and additional authentication

When a payment requires Strong Customer Authentication (SCA), `exchange()` returns a `PaymentStatus::Redirect`
state with the next-action URL in `$state->metadata['redirectUrl']`. The controller handles this automatically
by redirecting the buyer back to Stripe's authentication page. After the buyer completes authentication, Stripe
redirects them back to your `return_url` and also sends a `payment_intent.succeeded` webhook.

## License

MIT